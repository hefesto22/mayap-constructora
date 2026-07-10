<?php

declare(strict_types=1);

use App\Enums\TipoMovimientoInventario;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\MovimientoInventario;
use App\Models\Proyecto;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Reportes\CostoProyectoService;

/*
|--------------------------------------------------------------------------
| Materiales de CONSUMO INMEDIATO (agua de pipa y consumibles no
| almacenables): compra directa a obra → costo imputado + consumo
| automático → existencia neta cero, sin stock fantasma.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(ConfirmarCompraService::class);
    $this->proyecto = Proyecto::factory()->create();
    $this->agua = Material::factory()->create([
        'nombre'            => 'AGUA',
        'consumo_inmediato' => true,
    ]);
});

test('GOLDEN: la pipa de agua imputa costo a la obra y no deja stock fantasma', function (): void {
    // Pipa de 30 M³ a L. 100 el M³, directa a obra.
    $compra = Compra::factory()->directaAObra($this->proyecto)->create([
        'aplica_isv' => false, 'isv_porcentaje' => 0,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $this->agua->id,
        'cantidad'  => 30, 'costo_unitario' => 100,
    ]);

    $this->service->confirmar($compra);

    // Existencia neta CERO en la obra (entró y se consumió).
    $existencia = Existencia::query()
        ->where('material_id', $this->agua->id)
        ->where('proyecto_id', $this->proyecto->id)
        ->first();

    expect($existencia === null || bccomp((string) $existencia->cantidad, '0', 4) === 0)->toBeTrue();

    // Trazabilidad completa: entrada de compra + consumo, ambos enlazados.
    // pluck('tipo') devuelve enums (cast del modelo) — comparar por value.
    $tipos = MovimientoInventario::query()
        ->where('referencia_type', $compra->getMorphClass())
        ->where('referencia_id', $compra->id)
        ->pluck('tipo')
        ->map(fn (TipoMovimientoInventario $tipo): string => $tipo->value)
        ->all();

    expect($tipos)->toContain(TipoMovimientoInventario::EntradaCompra->value)
        ->and($tipos)->toContain(TipoMovimientoInventario::ConsumoObra->value);

    // El costo SÍ quedó imputado a la obra (3,000).
    $costo = app(CostoProyectoService::class)->calcular($this->proyecto->fresh());

    expect($costo->costoMateriales)->toBe('3000.00');
});

test('rechaza comprar un material de consumo inmediato A BODEGA', function (): void {
    $bodega = Bodega::factory()->create();

    $compra = Compra::factory()->paraBodega($bodega)->create();
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $this->agua->id,
        'cantidad'  => 30, 'costo_unitario' => 100,
    ]);

    expect(fn () => $this->service->confirmar($compra))
        ->toThrow(CompraNoConfirmableException::class);
});

test('en compra mixta, solo la línea de consumo inmediato se auto-consume', function (): void {
    $cemento = Material::factory()->create(['nombre' => 'CEMENTO']);

    $compra = Compra::factory()->directaAObra($this->proyecto)->create([
        'aplica_isv' => false, 'isv_porcentaje' => 0,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $this->agua->id,
        'cantidad'  => 30, 'costo_unitario' => 100,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $cemento->id,
        'cantidad'  => 50, 'costo_unitario' => 220,
    ]);

    $this->service->confirmar($compra);

    // El cemento SÍ queda en la existencia de la obra (es almacenable).
    $stockCemento = Existencia::query()
        ->where('material_id', $cemento->id)
        ->where('proyecto_id', $this->proyecto->id)
        ->firstOrFail();

    expect($stockCemento->cantidad)->toBe('50.0000');

    // Solo hay UN consumo automático (el del agua).
    $consumos = MovimientoInventario::query()
        ->where('tipo', TipoMovimientoInventario::ConsumoObra->value)
        ->where('referencia_id', $compra->id)
        ->get();

    expect($consumos)->toHaveCount(1)
        ->and($consumos->first()->material_id)->toBe($this->agua->id);
});
