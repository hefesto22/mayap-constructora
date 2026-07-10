<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;
use App\Enums\TipoMovimientoInventario;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\MovimientoInventario;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Reportes\CostoProyectoService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Compra con entrega DIRECTA A OBRA (drop-shipping de ferretería).
|--------------------------------------------------------------------------
| El material nunca pasa por bodega: la entrada de inventario cae en la
| existencia de la obra al costo real de factura, el costo se imputa al
| proyecto, y si hay requisición enlazada sus líneas quedan despachadas.
*/

beforeEach(function (): void {
    $this->service = app(ConfirmarCompraService::class);
    $this->proyecto = Proyecto::factory()->create();
});

test('GOLDEN: compra directa a obra imputa stock y costo a la obra, no a bodega', function (): void {
    $material = Material::factory()->create();

    $compra = Compra::factory()->directaAObra($this->proyecto)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 40, 'costo_unitario' => 25,
    ]);

    $this->service->confirmar($compra);

    // Existencia en la OBRA (no en bodega).
    $enObra = Existencia::query()
        ->where('material_id', $material->id)
        ->where('proyecto_id', $this->proyecto->id)
        ->firstOrFail();

    expect($enObra->cantidad)->toBe('40.0000')
        ->and(Existencia::query()->whereNotNull('bodega_id')->where('material_id', $material->id)->exists())->toBeFalse();

    // El movimiento quedó como entrada de compra con destino obra.
    $movimiento = MovimientoInventario::query()
        ->where('tipo', TipoMovimientoInventario::EntradaCompra->value)
        ->where('proyecto_destino_id', $this->proyecto->id)
        ->firstOrFail();

    expect($movimiento->valor_total)->toBe('1000.00');

    // El costo real de la obra incluye la compra directa (40 × 25 = 1000).
    $costo = app(CostoProyectoService::class)->calcular($this->proyecto->fresh());

    expect($costo->costoMateriales)->toBe('1000.00');
});

test('compra directa enlazada a requisición despacha sus líneas y avanza el estado', function (): void {
    $material = Material::factory()->create();

    $requisicion = Requisicion::factory()
        ->paraProyecto($this->proyecto)
        ->enEstado(EstadoRequisicion::RequisicionCompra)
        ->create();
    RequisicionLinea::factory()->paraMaterial($material)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '50.0000',
        'cantidad_autorizada' => '50.0000',
    ]);

    $compra = Compra::factory()
        ->directaAObra($this->proyecto)
        ->paraRequisicion($requisicion)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 50, 'costo_unitario' => 10,
    ]);

    $this->service->confirmar($compra);

    $requisicion->refresh();
    $linea = $requisicion->lineas()->firstOrFail();

    expect($requisicion->estado)->toBe(EstadoRequisicion::Despachada)
        ->and($linea->cantidad_despachada)->toBe('50.0000');

    // La transición quedó en bitácora con la referencia a la compra.
    expect($requisicion->transiciones()->where('estado_destino', EstadoRequisicion::Despachada->value)->exists())
        ->toBeTrue();
});

test('compra parcial deja la línea despachada solo por lo comprado', function (): void {
    $material = Material::factory()->create();

    $requisicion = Requisicion::factory()
        ->paraProyecto($this->proyecto)
        ->enEstado(EstadoRequisicion::RequisicionCompra)
        ->create();
    RequisicionLinea::factory()->paraMaterial($material)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '100.0000',
        'cantidad_autorizada' => '100.0000',
    ]);

    $compra = Compra::factory()
        ->directaAObra($this->proyecto)
        ->paraRequisicion($requisicion)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 30, 'costo_unitario' => 10,
    ]);

    $this->service->confirmar($compra);

    expect($requisicion->fresh()->lineas()->firstOrFail()->cantidad_despachada)->toBe('30.0000');
});

test('rechaza confirmar cuando la requisición es de OTRA obra', function (): void {
    $otraObra = Proyecto::factory()->create();
    $requisicion = Requisicion::factory()->paraProyecto($otraObra)->create();

    $compra = Compra::factory()
        ->directaAObra($this->proyecto)
        ->paraRequisicion($requisicion)
        ->create();
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => Material::factory()->create()->id,
        'cantidad'  => 10, 'costo_unitario' => 5,
    ]);

    expect(fn () => $this->service->confirmar($compra))
        ->toThrow(CompraNoConfirmableException::class);
});

test('la DB rechaza una compra con bodega Y obra al mismo tiempo (CHECK XOR)', function (): void {
    $compra = Compra::factory()->create(); // con bodega

    expect(fn () => Compra::query()->whereKey($compra->id)->update(['proyecto_id' => $this->proyecto->id]))
        ->toThrow(QueryException::class);
});

test('la DB rechaza una compra sin ningún destino', function (): void {
    expect(fn () => Compra::factory()->create(['bodega_id' => null]))
        ->toThrow(QueryException::class);
});
