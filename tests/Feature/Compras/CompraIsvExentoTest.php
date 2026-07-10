<?php

declare(strict_types=1);

use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Existencia;
use App\Models\Material;
use App\Services\Compras\ConfirmarCompraService;

/*
|--------------------------------------------------------------------------
| ISV por línea (facturas mixtas gravado/exento, formato SAR).
|--------------------------------------------------------------------------
| El ISV se calcula SOLO sobre el valor efectivo de las líneas gravadas.
| El ISV nunca capitaliza al inventario (crédito fiscal, no costo).
*/

beforeEach(function (): void {
    $this->service = app(ConfirmarCompraService::class);
    $this->bodega = Bodega::factory()->create();
});

test('GOLDEN: factura mixta calcula ISV solo sobre las líneas gravadas', function (): void {
    $gravado = Material::factory()->create();
    $exento = Material::factory()->create(['exento_isv' => true]);

    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv' => true, 'isv_porcentaje' => 15,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $gravado->id,
        'cantidad'  => 100, 'costo_unitario' => 10, // 1000 gravado
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $exento->id,
        'cantidad'  => 50, 'costo_unitario' => 10, 'exento' => true, // 500 exento
    ]);

    $this->service->confirmar($compra);
    $compra->refresh();

    // ISV = 15% de 1000 (no de 1500); total = 1500 + 150.
    expect($compra->subtotal_cache)->toBe('1500.00')
        ->and($compra->isv_cache)->toBe('150.00')
        ->and($compra->total_cache)->toBe('1650.00');
});

test('compra completamente exenta por líneas no genera ISV aunque el toggle esté activo', function (): void {
    $material = Material::factory()->create(['exento_isv' => true]);

    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv' => true, 'isv_porcentaje' => 15,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 10, 'costo_unitario' => 100, 'exento' => true,
    ]);

    $this->service->confirmar($compra);

    expect($compra->fresh()->isv_cache)->toBe('0.00')
        ->and($compra->fresh()->total_cache)->toBe('1000.00');
});

test('el flete prorrateado a una línea gravada SÍ entra a la base del ISV; el de la exenta NO', function (): void {
    $gravado = Material::factory()->create();
    $exento = Material::factory()->create(['exento_isv' => true]);

    // Gravado 1000 (2/3) · Exento 500 (1/3) · flete 300 → gravado absorbe 200.
    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv' => true, 'isv_porcentaje' => 15, 'costo_envio' => 300,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $gravado->id,
        'cantidad'  => 100, 'costo_unitario' => 10,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $exento->id,
        'cantidad'  => 50, 'costo_unitario' => 10, 'exento' => true,
    ]);

    $this->service->confirmar($compra);
    $compra->refresh();

    // Base gravada = 1000 + 200 de flete = 1200 → ISV 180.
    // Total = 1500 + 300 + 180 = 1980.
    expect($compra->isv_cache)->toBe('180.00')
        ->and($compra->total_cache)->toBe('1980.00');
});

test('el ISV no capitaliza: el costo del inventario es el neto', function (): void {
    $material = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv' => true, 'isv_porcentaje' => 15,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 100, 'costo_unitario' => 10,
    ]);

    $this->service->confirmar($compra);

    $stock = Existencia::query()->where('material_id', $material->id)->firstOrFail();

    // Costo 10.00 neto — el ISV (150) va a la CxP, no al inventario.
    expect($stock->costo_promedio)->toBe('10.00')
        ->and($compra->fresh()->total_cache)->toBe('1150.00');
});

test('material se crea gravado por defecto y exento_isv castea a boolean', function (): void {
    $material = Material::factory()->create();
    $exento = Material::factory()->create(['exento_isv' => true]);

    expect($material->exento_isv)->toBeFalse()
        ->and($exento->exento_isv)->toBeTrue();
});
