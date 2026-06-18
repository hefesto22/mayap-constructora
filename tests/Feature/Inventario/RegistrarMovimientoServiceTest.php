<?php

declare(strict_types=1);

use App\Enums\TipoMovimientoInventario;
use App\Exceptions\Inventario\MovimientoInvalidoException;
use App\Exceptions\Inventario\StockInsuficienteException;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\MovimientoInventario;
use App\Models\Proyecto;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;

/*
|--------------------------------------------------------------------------
| Tests del Service de inventario (promedio ponderado móvil).
|--------------------------------------------------------------------------
| Verifican el motor de costeo WAC: el promedio queda invariante ante
| salidas, se mueve solo con entradas, el valor se conserva en traslados,
| y las reglas de dominio (stock insuficiente, motivo obligatorio) fallan
| temprano. El GOLDEN test reproduce el ejemplo numérico acordado con el
| dueño: 10@10 → 10@15 → uso 10 → 10@17 = promedio 14.75.
*/

beforeEach(function (): void {
    $this->service = new RegistrarMovimientoService;
    $this->item = Item::factory()->create();
    $this->bodega = Bodega::factory()->create();
});

function existenciaBodega(int $itemId, int $bodegaId): Existencia
{
    return Existencia::query()
        ->where('item_id', $itemId)
        ->where('bodega_id', $bodegaId)
        ->firstOrFail();
}

// ─── GOLDEN TEST ────────────────────────────────────────────────────

test('GOLDEN: promedio ponderado móvil reproduce 10@10 → 10@15 → uso 10 → 10@17 = 14.75', function (): void {
    $bodega = Ubicacion::bodega($this->bodega->id);

    // 1. Compro 10 @ 10  → 10 u, valor 100, promedio 10.00
    $this->service->entradaCompra($this->item->id, $bodega, '10', '10');
    $e = existenciaBodega($this->item->id, $this->bodega->id);
    expect($e->cantidad)->toBe('10.0000')
        ->and($e->valor_total)->toBe('100.00')
        ->and($e->costo_promedio)->toBe('10.00');

    // 2. Compro 10 @ 15  → 20 u, valor 250, promedio 12.50
    $this->service->entradaCompra($this->item->id, $bodega, '10', '15');
    $e->refresh();
    expect($e->cantidad)->toBe('20.0000')
        ->and($e->valor_total)->toBe('250.00')
        ->and($e->costo_promedio)->toBe('12.50');

    // 3. Uso 10 (ajuste negativo) → 10 u, valor 125, promedio SIGUE 12.50
    $this->service->ajusteNegativo($this->item->id, $bodega, '10', 'consumo de prueba');
    $e->refresh();
    expect($e->cantidad)->toBe('10.0000')
        ->and($e->valor_total)->toBe('125.00')
        ->and($e->costo_promedio)->toBe('12.50');

    // 4. Compro 10 @ 17  → 20 u, valor 295, promedio 14.75
    $this->service->entradaCompra($this->item->id, $bodega, '10', '17');
    $e->refresh();
    expect($e->cantidad)->toBe('20.0000')
        ->and($e->valor_total)->toBe('295.00')
        ->and($e->costo_promedio)->toBe('14.75');
});

// ─── Entrada por compra ─────────────────────────────────────────────

test('entrada por compra crea la existencia si no existía', function (): void {
    $bodega = Ubicacion::bodega($this->bodega->id);

    $resultado = $this->service->entradaCompra($this->item->id, $bodega, '50', '8.50');

    expect($resultado->tipo)->toBe(TipoMovimientoInventario::EntradaCompra)
        ->and($resultado->destino?->cantidad)->toBe('50.0000')
        ->and($resultado->destino?->costoPromedio)->toBe('8.50');

    expect(Existencia::query()->where('item_id', $this->item->id)->count())->toBe(1);
});

// ─── Despacho a obra (multi-ubicación, caso de las 130) ─────────────

test('despacho de bodega a obra incrementa la existencia de la obra (caso 130)', function (): void {
    $proyecto = Proyecto::factory()->create();
    $bodega = Ubicacion::bodega($this->bodega->id);
    $obra = Ubicacion::obra($proyecto->id);

    // La obra ya tenía 30 (ajuste inicial), la bodega tiene 100.
    $this->service->ajustePositivo($this->item->id, $obra, '30', '12', 'inventario inicial obra');
    $this->service->entradaCompra($this->item->id, $bodega, '100', '12');

    // Se despachan 100 → la obra queda en 130.
    $resultado = $this->service->salidaDespacho($this->item->id, $bodega, $obra, '100');

    expect($resultado->origen?->cantidad)->toBe('0.0000')
        ->and($resultado->destino?->cantidad)->toBe('130.0000');

    $existenciaObra = Existencia::query()
        ->where('item_id', $this->item->id)
        ->where('proyecto_id', $proyecto->id)
        ->firstOrFail();

    expect($existenciaObra->cantidad)->toBe('130.0000');
});

// ─── Traslado conserva valor total del sistema ──────────────────────

test('traslado conserva el valor total entre ubicaciones', function (): void {
    $bodegaDestinoModel = Bodega::factory()->create();
    $origen = Ubicacion::bodega($this->bodega->id);
    $destino = Ubicacion::bodega($bodegaDestinoModel->id);

    $this->service->entradaCompra($this->item->id, $origen, '40', '25');

    $this->service->traslado($this->item->id, $origen, $destino, '15');

    $valorOrigen = existenciaBodega($this->item->id, $this->bodega->id)->valor_total;
    $valorDestino = existenciaBodega($this->item->id, $bodegaDestinoModel->id)->valor_total;

    // 40 × 25 = 1000 repartido: 25 quedan (625) + 15 trasladados (375).
    // La suma 625 + 375 = 1000 confirma que el valor se conserva al céntimo.
    expect($valorOrigen)->toBe('625.00')
        ->and($valorDestino)->toBe('375.00');
});

// ─── Consumo en obra ────────────────────────────────────────────────

test('consumo en obra baja la existencia de la obra sin destino', function (): void {
    $proyecto = Proyecto::factory()->create();
    $obra = Ubicacion::obra($proyecto->id);

    $this->service->ajustePositivo($this->item->id, $obra, '20', '5', 'inicial');
    $resultado = $this->service->consumoObra($this->item->id, $obra, '8', 'fundición de zapata');

    expect($resultado->destino)->toBeNull()
        ->and($resultado->origen?->cantidad)->toBe('12.0000');
});

// ─── Reglas de dominio (fail fast) ──────────────────────────────────

test('lanza StockInsuficienteException al sacar más de lo disponible', function (): void {
    $bodega = Ubicacion::bodega($this->bodega->id);
    $this->service->entradaCompra($this->item->id, $bodega, '5', '10');

    $this->service->ajusteNegativo($this->item->id, $bodega, '6', 'merma');
})->throws(StockInsuficienteException::class);

test('lanza StockInsuficienteException si la existencia no existe', function (): void {
    $bodega = Ubicacion::bodega($this->bodega->id);

    $this->service->ajusteNegativo($this->item->id, $bodega, '1', 'merma');
})->throws(StockInsuficienteException::class);

test('un ajuste sin motivo es rechazado', function (): void {
    $bodega = Ubicacion::bodega($this->bodega->id);

    $this->service->ajusteNegativo($this->item->id, $bodega, '1', '   ');
})->throws(MovimientoInvalidoException::class);

test('cantidad cero o negativa es rechazada', function (): void {
    $bodega = Ubicacion::bodega($this->bodega->id);

    $this->service->entradaCompra($this->item->id, $bodega, '0', '10');
})->throws(MovimientoInvalidoException::class);

test('traslado a la misma ubicación es rechazado', function (): void {
    $bodega = Ubicacion::bodega($this->bodega->id);
    $this->service->entradaCompra($this->item->id, $bodega, '10', '10');

    $this->service->traslado($this->item->id, $bodega, $bodega, '5');
})->throws(MovimientoInvalidoException::class);

// ─── Libro mayor ────────────────────────────────────────────────────

test('cada operación escribe un renglón en el libro mayor con su tipo', function (): void {
    $bodega = Ubicacion::bodega($this->bodega->id);

    $this->service->entradaCompra($this->item->id, $bodega, '10', '10');
    $this->service->ajusteNegativo($this->item->id, $bodega, '3', 'merma');

    expect(MovimientoInventario::query()->count())->toBe(2)
        ->and(MovimientoInventario::query()->where('tipo', TipoMovimientoInventario::EntradaCompra->value)->exists())->toBeTrue()
        ->and(MovimientoInventario::query()->where('tipo', TipoMovimientoInventario::AjusteNegativo->value)->exists())->toBeTrue();
});

test('el movimiento de salida registra el costo promedio vigente como costo unitario', function (): void {
    $bodega = Ubicacion::bodega($this->bodega->id);

    // promedio = (10×10 + 10×20) / 20 = 15.00
    $this->service->entradaCompra($this->item->id, $bodega, '10', '10');
    $this->service->entradaCompra($this->item->id, $bodega, '10', '20');
    $this->service->ajusteNegativo($this->item->id, $bodega, '5', 'merma');

    $salida = MovimientoInventario::query()
        ->where('tipo', TipoMovimientoInventario::AjusteNegativo->value)
        ->firstOrFail();

    expect($salida->costo_unitario)->toBe('15.0000')
        ->and($salida->valor_total)->toBe('75.00');
});
