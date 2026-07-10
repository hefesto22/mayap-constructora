<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Enums\EstadoRequisicion;
use App\Enums\TipoMovimientoInventario;
use App\Exceptions\Compras\CompraNoAnulableException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\CuentaPorPagar;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Services\Compras\AbonarService;
use App\Services\Compras\AnularCompraService;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Reportes\CostoProyectoService;
use App\Services\Requisiciones\TransicionarRequisicionService;

/*
|--------------------------------------------------------------------------
| Anulación de compras confirmadas — reversa exacta y reglas de bloqueo.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->confirmar = app(ConfirmarCompraService::class);
    $this->anular = app(AnularCompraService::class);
    $this->inventario = app(RegistrarMovimientoService::class);
    $this->bodega = Bodega::factory()->create();
});

/**
 * @param array<string, mixed> $estadoCompra
 */
function compraConfirmada(Bodega $bodega, Material $material, string $cantidad, string $costo, array $estadoCompra = []): Compra
{
    $compra = Compra::factory()->paraBodega($bodega)->create(
        ['aplica_isv' => false, 'isv_porcentaje' => 0] + $estadoCompra,
    );
    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => $material->id,
        'cantidad'       => $cantidad,
        'costo_unitario' => $costo,
    ]);

    app(ConfirmarCompraService::class)->confirmar($compra);

    return $compra->fresh();
}

test('GOLDEN: la anulación revierte al VALOR EXACTO y el WAC previo queda intacto', function (): void {
    $material = Material::factory()->create();

    // Stock previo: 100 @ L.20 = 2,000 (WAC 20).
    $this->inventario->entradaCompra(
        materialId: $material->id,
        destino: Ubicacion::bodega($this->bodega->id),
        cantidad: '100',
        costoUnitario: '20',
    );

    // La compra mete 100 @ L.10 = 1,000 → 200 unidades, valor 3,000 (WAC 15).
    $compra = compraConfirmada($this->bodega, $material, '100', '10');

    $this->anular->anular($compra, 'PRECIO CAPTURADO EQUIVOCADO');

    $stock = Existencia::query()
        ->where('material_id', $material->id)
        ->where('bodega_id', $this->bodega->id)
        ->firstOrFail();

    // Una reversa PROPORCIONAL habría dejado 100 @ 15 (distorsión). La
    // exacta deja el mundo como antes de la compra: 100 @ 20.
    expect($stock->cantidad)->toBe('100.0000')
        ->and($stock->valor_total)->toBe('2000.00')
        ->and($stock->costo_promedio)->toBe('20.00');

    $compra->refresh();

    expect($compra->estado)->toBe(EstadoCompra::Anulada)
        ->and($compra->motivo_anulacion)->toBe('PRECIO CAPTURADO EQUIVOCADO')
        ->and($compra->anulada_at)->not->toBeNull()
        ->and($compra->movimientos()->where('tipo', TipoMovimientoInventario::AnulacionCompra->value)->count())->toBe(1);
});

test('bloquea la anulación si parte del stock ya se despachó', function (): void {
    $material = Material::factory()->create();
    $obra = Proyecto::factory()->create();

    $compra = compraConfirmada($this->bodega, $material, '100', '10');

    // Ya se despacharon 60 a una obra: solo quedan 40 — no alcanza.
    $this->inventario->salidaDespacho(
        materialId: $material->id,
        origen: Ubicacion::bodega($this->bodega->id),
        destino: Ubicacion::obra($obra->id),
        cantidad: '60',
    );

    expect(fn () => $this->anular->anular($compra, 'INTENTO TARDÍO'))
        ->toThrow(CompraNoAnulableException::class, 'ya se usó');

    // Rollback total: nada cambió.
    expect($compra->fresh()->estado)->toBe(EstadoCompra::Confirmada);
});

test('la compra a crédito elimina su cuenta por pagar al anularse (sin abonos)', function (): void {
    $material = Material::factory()->create();
    $proveedor = Proveedor::factory()->aCredito(30)->create();

    $compra = Compra::factory()
        ->paraBodega($this->bodega)
        ->paraProveedor($proveedor)
        ->aCredito()
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 10, 'costo_unitario' => 100,
    ]);

    $this->confirmar->confirmar($compra);

    expect(CuentaPorPagar::query()->where('compra_id', $compra->id)->exists())->toBeTrue();

    $this->anular->anular($compra->fresh(), 'PROVEEDOR EQUIVOCADO');

    expect(CuentaPorPagar::query()->where('compra_id', $compra->id)->exists())->toBeFalse()
        ->and($compra->fresh()->estado)->toBe(EstadoCompra::Anulada);
});

test('bloquea la anulación si la cuenta por pagar ya tiene abonos', function (): void {
    $material = Material::factory()->create();
    $proveedor = Proveedor::factory()->aCredito(30)->create();

    $compra = Compra::factory()
        ->paraBodega($this->bodega)
        ->paraProveedor($proveedor)
        ->aCredito()
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 10, 'costo_unitario' => 100,
    ]);

    $this->confirmar->confirmar($compra);

    $cuenta = CuentaPorPagar::query()->where('compra_id', $compra->id)->firstOrFail();
    app(AbonarService::class)->abonar($cuenta, '200');

    expect(fn () => $this->anular->anular($compra->fresh(), 'YA NO LA QUIERO'))
        ->toThrow(CompraNoAnulableException::class, 'abonos');

    expect($compra->fresh()->estado)->toBe(EstadoCompra::Confirmada)
        ->and(CuentaPorPagar::query()->where('compra_id', $compra->id)->exists())->toBeTrue();
});

test('anula una compra de consumo inmediato: stock neto cero y costo de la obra en cero', function (): void {
    $agua = Material::factory()->create(['consumo_inmediato' => true]);
    $obra = Proyecto::factory()->create();

    $compra = Compra::factory()->directaAObra($obra)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $agua->id,
        'cantidad'  => 3, 'costo_unitario' => 1000,
    ]);

    $this->confirmar->confirmar($compra);

    // Confirmada: el costo de la obra subió 3,000 (entrada) y el stock es 0.
    expect(app(CostoProyectoService::class)->calcular($obra->fresh())->costoMateriales)->toBe('3000.00');

    $this->anular->anular($compra->fresh(), 'LA PIPA NUNCA LLEGÓ');

    $stock = Existencia::query()
        ->where('material_id', $agua->id)
        ->where('proyecto_id', $obra->id)
        ->firstOrFail();

    expect($stock->cantidad)->toBe('0.0000')
        ->and($stock->valor_total)->toBe('0.00')
        ->and(app(CostoProyectoService::class)->calcular($obra->fresh())->costoMateriales)->toBe('0.00');
});

test('la requisición despachada por la compra regresa a Requisición de compra con cantidades revertidas', function (): void {
    $cemento = Material::factory()->create();
    $obra = Proyecto::factory()->create();

    $requisicion = Requisicion::factory()
        ->paraProyecto($obra)
        ->enEstado(EstadoRequisicion::RequisicionCompra)
        ->create();
    $linea = RequisicionLinea::factory()->paraMaterial($cemento)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '100.0000',
        'cantidad_autorizada' => '100.0000',
    ]);

    $compra = Compra::factory()
        ->directaAObra($obra)
        ->paraRequisicion($requisicion)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $cemento->id,
        'cantidad'  => 100, 'costo_unitario' => 220,
    ]);

    $this->confirmar->confirmar($compra);

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Despachada)
        ->and($linea->fresh()->cantidad_despachada)->toBe('100.0000');

    $this->anular->anular($compra->fresh(), 'FACTURA EQUIVOCADA');

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::RequisicionCompra)
        ->and($linea->fresh()->cantidad_despachada)->toBe('0.0000')
        ->and($requisicion->transiciones()->latest('id')->value('nota'))->toContain('anulada');
});

test('bloquea la anulación si la requisición ya avanzó más allá de Despachada', function (): void {
    $cemento = Material::factory()->create();
    $obra = Proyecto::factory()->create();

    $requisicion = Requisicion::factory()
        ->paraProyecto($obra)
        ->enEstado(EstadoRequisicion::RequisicionCompra)
        ->create();
    RequisicionLinea::factory()->paraMaterial($cemento)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '100.0000',
        'cantidad_autorizada' => '100.0000',
    ]);

    $compra = Compra::factory()
        ->directaAObra($obra)
        ->paraRequisicion($requisicion)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $cemento->id,
        'cantidad'  => 100, 'costo_unitario' => 220,
    ]);

    $this->confirmar->confirmar($compra);

    app(TransicionarRequisicionService::class)
        ->marcarEnTransito($requisicion->fresh());

    expect(fn () => $this->anular->anular($compra->fresh(), 'TARDE'))
        ->toThrow(CompraNoAnulableException::class, 'avanzó');

    expect($compra->fresh()->estado)->toBe(EstadoCompra::Confirmada);
});

test('rechaza anular sin motivo y anular dos veces', function (): void {
    $material = Material::factory()->create();
    $compra = compraConfirmada($this->bodega, $material, '10', '10');

    expect(fn () => $this->anular->anular($compra, '   '))
        ->toThrow(CompraNoAnulableException::class, 'motivo');

    $this->anular->anular($compra, 'ERROR DE CAPTURA');

    expect(fn () => $this->anular->anular($compra->fresh(), 'OTRA VEZ'))
        ->toThrow(CompraNoAnulableException::class);
});
