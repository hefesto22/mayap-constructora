<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Enums\FaseMantenimiento;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\MantenimientoMaquina;
use App\Models\MovimientoInventario;
use App\Services\Compras\AvisarLlegadasComprasService;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Compras\MarcarPorRecibirService;
use App\Services\Compras\SincronizarRepuestosMantenimientoService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Golden tests de las compras LIBRES (decisión Mauricio 2026-07-20):
| taller/equipo/oficina sin catálogo — líneas a mano, gasto directo sin
| inventario, con pedido por espera (fecha estimada + campanita) y
| vínculo opcional al mantenimiento de la máquina.
|--------------------------------------------------------------------------
*/

/**
 * @param array<string, mixed> $attrs
 */
function compraLibre(array $attrs = []): Compra
{
    $compra = Compra::factory()->create([
        'categoria' => 'taller',
        ...$attrs,
    ]);

    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => null,
        'descripcion'    => 'FILTRO DE ACEITE 1R-0750',
        'cantidad'       => 2,
        'costo_unitario' => 500,
        'exento'         => false,
    ]);

    return $compra;
}

// ─── Confirmación: gasto directo, sin inventario ───────────────────────

test('la compra libre confirmada suma totales pero NO mueve inventario', function (): void {
    $compra = compraLibre();

    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => null,
        'descripcion'    => 'ACEITE HIDRAULICO 5 GAL',
        'cantidad'       => 1,
        'costo_unitario' => 230,
        'exento'         => false,
    ]);

    app(ConfirmarCompraService::class)->confirmar($compra->refresh());

    $compra->refresh();

    // 2×500 + 1×230 = 1,230 + ISV 15% = 1,414.50 — pero CERO movimientos.
    expect($compra->estado)->toBe(EstadoCompra::Confirmada)
        ->and($compra->subtotal_cache)->toBe('1230.00')
        ->and($compra->total_cache)->toBe('1414.50')
        ->and(MovimientoInventario::query()->count())->toBe(0)
        ->and($compra->cuentaPorPagar)->toBeNull(); // contado: sin CxP
});

test('la compra libre a crédito genera su cuenta por pagar completa', function (): void {
    $compra = compraLibre(['condicion_pago' => 'credito']);

    app(ConfirmarCompraService::class)->confirmar($compra->refresh());

    $cuenta = $compra->refresh()->cuentaPorPagar;

    expect($cuenta)->not->toBeNull()
        ->and((string) $cuenta->monto_original)->toBe('1150.00') // 1,000 + ISV
        ->and(MovimientoInventario::query()->count())->toBe(0);
});

test('una línea sin material NI descripción la rechaza la base', function (): void {
    $compra = compraLibre();

    expect(fn () => DB::table('compra_lineas')->insert([
        'compra_id'      => $compra->id,
        'material_id'    => null,
        'descripcion'    => null,
        'cantidad'       => 1,
        'costo_unitario' => 10,
        'subtotal'       => 10,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]))->toThrow(QueryException::class);
});

// ─── Pedido con espera: campanita el día estimado ──────────────────────

test('el pedido avisa el día estimado una sola vez', function (): void {
    $compra = compraLibre(['fecha_estimada_llegada' => today()]);

    app(MarcarPorRecibirService::class)->registrar($compra->refresh());

    expect($compra->refresh()->estado)->toBe(EstadoCompra::PorRecibir);

    $avisador = app(AvisarLlegadasComprasService::class);

    expect($avisador->avisar())->toBe(1)
        ->and($avisador->avisar())->toBe(0)
        ->and($compra->refresh()->aviso_llegada_at)->not->toBeNull();
});

test('un pedido con fecha estimada futura no dispara la campanita', function (): void {
    $compra = compraLibre(['fecha_estimada_llegada' => today()->addDays(6)]);

    app(MarcarPorRecibirService::class)->registrar($compra->refresh());

    expect(app(AvisarLlegadasComprasService::class)->avisar())->toBe(0);
});

// ─── Vínculo con el mantenimiento de la máquina ────────────────────────

test('registrar el pedido alimenta la fecha de repuestos del mantenimiento y su bitácora', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create([
        'fase' => FaseMantenimiento::CompraRepuestos->value,
    ]);

    $compra = compraLibre([
        'mantenimiento_id'       => $mantenimiento->id,
        'fecha_estimada_llegada' => today()->addDays(4),
    ]);

    app(MarcarPorRecibirService::class)->registrar($compra->refresh());

    $mantenimiento->refresh();

    expect($mantenimiento->fecha_estimada_repuestos?->toDateString())
        ->toBe(today()->addDays(4)->toDateString())
        ->and($mantenimiento->aviso_repuestos_at)->toBeNull()
        ->and($mantenimiento->bitacoras()->count())->toBe(1)
        ->and((string) $mantenimiento->bitacoras()->first()?->detalle)->toContain($compra->refresh()->codigo);

    // Al recibir la compra, la llegada queda anotada también.
    app(SincronizarRepuestosMantenimientoService::class)->llegadaRegistrada($compra->refresh());

    expect($mantenimiento->bitacoras()->count())->toBe(2);
});
