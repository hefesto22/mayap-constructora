<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\MovimientoInventario;
use App\Services\Compras\ConfirmarCompraService;

/*
|--------------------------------------------------------------------------
| Tests del ConfirmarCompraService — el puente Compras → Inventario (WAC).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(ConfirmarCompraService::class);
    $this->bodega = Bodega::factory()->create();
});

test('GOLDEN: confirmar registra stock con WAC y calcula totales con ISV', function (): void {
    $materialA = Material::factory()->create();
    $materialB = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv'     => true,
        'isv_porcentaje' => 15.00,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $materialA->id, 'cantidad' => 100, 'costo_unitario' => 10,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $materialB->id, 'cantidad' => 50, 'costo_unitario' => 20,
    ]);

    $this->service->confirmar($compra);

    $compra->refresh();

    // Totales: subtotal 1000 + 1000 = 2000; ISV 15% = 300; total 2300.
    expect($compra->estado)->toBe(EstadoCompra::Confirmada)
        ->and($compra->subtotal_cache)->toBe('2000.00')
        ->and($compra->isv_cache)->toBe('300.00')
        ->and($compra->total_cache)->toBe('2300.00')
        ->and($compra->fecha_recepcion)->not->toBeNull();

    // Stock real con su costo promedio.
    $stockA = Existencia::query()->where('material_id', $materialA->id)->where('bodega_id', $this->bodega->id)->firstOrFail();
    $stockB = Existencia::query()->where('material_id', $materialB->id)->where('bodega_id', $this->bodega->id)->firstOrFail();

    expect($stockA->cantidad)->toBe('100.0000')
        ->and($stockA->costo_promedio)->toBe('10.00')
        ->and($stockB->cantidad)->toBe('50.0000')
        ->and($stockB->costo_promedio)->toBe('20.00');
});

test('cada movimiento de inventario queda enlazado a la compra', function (): void {
    $material = Material::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create();
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 30, 'costo_unitario' => 12,
    ]);

    $this->service->confirmar($compra);

    $movimiento = MovimientoInventario::query()
        ->where('referencia_type', $compra->getMorphClass())
        ->where('referencia_id', $compra->id)
        ->firstOrFail();

    expect($movimiento->material_id)->toBe($material->id)
        ->and($movimiento->cantidad)->toBe('30.0000');
});

test('una compra sin ISV calcula total = subtotal', function (): void {
    $material = Material::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv'     => false,
        'isv_porcentaje' => 0,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 10, 'costo_unitario' => 50,
    ]);

    $this->service->confirmar($compra);
    $compra->refresh();

    expect($compra->subtotal_cache)->toBe('500.00')
        ->and($compra->isv_cache)->toBe('0.00')
        ->and($compra->total_cache)->toBe('500.00');
});

test('no se puede confirmar una compra ya confirmada', function (): void {
    $material = Material::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create();
    CompraLinea::factory()->create(['compra_id' => $compra->id, 'material_id' => $material->id]);

    $this->service->confirmar($compra);
    $this->service->confirmar($compra->fresh());
})->throws(CompraNoConfirmableException::class);

test('no se puede confirmar una compra sin líneas', function (): void {
    $compra = Compra::factory()->paraBodega($this->bodega)->create();

    $this->service->confirmar($compra);
})->throws(CompraNoConfirmableException::class);

test('FACTURA AL CENTAVO: 100 unidades a L 10.00 de factura cuadran en L 1,000.00 exactos', function (): void {
    // Caso real (Mauricio 2026-07-20): agua de pipa a L 10.00 con ISV
    // incluido. El neto redondeado (10 / 1.15 ≈ 8.6957) multiplicado por
    // la cantidad arrastraba centavos (L 1,000.50): con el precio de
    // factura guardado, el total reproduce el papel EXACTO.
    $material = Material::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv'     => true,
        'isv_porcentaje' => 15.00,
    ]);
    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => $material->id,
        'cantidad'       => 100,
        'costo_unitario' => '8.6957',   // lo que deduce el form
        'precio_factura' => '10.0000',  // lo que dice el papel
    ]);

    $this->service->confirmar($compra);
    $compra->refresh();

    // Bruto 100 × 10 = 1,000.00; neto 869.57; ISV por diferencia 130.43.
    expect($compra->subtotal_cache)->toBe('869.57')
        ->and($compra->isv_cache)->toBe('130.43')
        ->and($compra->total_cache)->toBe('1000.00');
});

test('sin precio de factura el ISV sale del bruto reconstruido (sin arrastre de centavos)', function (): void {
    $material = Material::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv'     => true,
        'isv_porcentaje' => 15.00,
    ]);
    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => $material->id,
        'cantidad'       => 100,
        'costo_unitario' => '8.6957', // neto tecleado a mano, sin precio de factura
    ]);

    $this->service->confirmar($compra);
    $compra->refresh();

    // 869.57 × 1.15 = 999.9955 → bruto 1,000.01... redondeo del neto a 4
    // decimales: el total queda a UN centavo del papel, no a cincuenta.
    expect($compra->subtotal_cache)->toBe('869.57')
        ->and($compra->isv_cache)->toBe('130.44')
        ->and($compra->total_cache)->toBe('1000.01');
});
