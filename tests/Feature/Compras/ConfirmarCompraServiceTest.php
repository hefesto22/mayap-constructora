<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\MovimientoInventario;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Inventario\RegistrarMovimientoService;

/*
|--------------------------------------------------------------------------
| Tests del ConfirmarCompraService — el puente Compras → Inventario (WAC).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = new ConfirmarCompraService(new RegistrarMovimientoService);
    $this->bodega = Bodega::factory()->create();
});

test('GOLDEN: confirmar registra stock con WAC y calcula totales con ISV', function (): void {
    $itemA = Item::factory()->create();
    $itemB = Item::factory()->create();

    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv'     => true,
        'isv_porcentaje' => 15.00,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'item_id' => $itemA->id, 'cantidad' => 100, 'costo_unitario' => 10,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'item_id' => $itemB->id, 'cantidad' => 50, 'costo_unitario' => 20,
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
    $stockA = Existencia::query()->where('item_id', $itemA->id)->where('bodega_id', $this->bodega->id)->firstOrFail();
    $stockB = Existencia::query()->where('item_id', $itemB->id)->where('bodega_id', $this->bodega->id)->firstOrFail();

    expect($stockA->cantidad)->toBe('100.0000')
        ->and($stockA->costo_promedio)->toBe('10.00')
        ->and($stockB->cantidad)->toBe('50.0000')
        ->and($stockB->costo_promedio)->toBe('20.00');
});

test('cada movimiento de inventario queda enlazado a la compra', function (): void {
    $item = Item::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create();
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'item_id' => $item->id, 'cantidad' => 30, 'costo_unitario' => 12,
    ]);

    $this->service->confirmar($compra);

    $movimiento = MovimientoInventario::query()
        ->where('referencia_type', $compra->getMorphClass())
        ->where('referencia_id', $compra->id)
        ->firstOrFail();

    expect($movimiento->item_id)->toBe($item->id)
        ->and($movimiento->cantidad)->toBe('30.0000');
});

test('una compra sin ISV calcula total = subtotal', function (): void {
    $item = Item::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv'     => false,
        'isv_porcentaje' => 0,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'item_id' => $item->id, 'cantidad' => 10, 'costo_unitario' => 50,
    ]);

    $this->service->confirmar($compra);
    $compra->refresh();

    expect($compra->subtotal_cache)->toBe('500.00')
        ->and($compra->isv_cache)->toBe('0.00')
        ->and($compra->total_cache)->toBe('500.00');
});

test('no se puede confirmar una compra ya confirmada', function (): void {
    $item = Item::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create();
    CompraLinea::factory()->create(['compra_id' => $compra->id, 'item_id' => $item->id]);

    $this->service->confirmar($compra);
    $this->service->confirmar($compra->fresh());
})->throws(CompraNoConfirmableException::class);

test('no se puede confirmar una compra sin líneas', function (): void {
    $compra = Compra::factory()->paraBodega($this->bodega)->create();

    $this->service->confirmar($compra);
})->throws(CompraNoConfirmableException::class);
