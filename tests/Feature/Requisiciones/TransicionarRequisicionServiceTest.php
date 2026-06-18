<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;
use App\Exceptions\Requisiciones\RequisicionInvalidaException;
use App\Exceptions\Requisiciones\TransicionInvalidaException;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\MovimientoInventario;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Requisiciones\TransicionarRequisicionService;

/*
|--------------------------------------------------------------------------
| Tests del flujo completo de requisiciones (máquina de estados + WAC).
|--------------------------------------------------------------------------
| El GOLDEN flow recorre solicitud → autorización → despacho (que descuenta
| stock real con promedio ponderado) → tránsito → recepción → cierre, y los
| casos clave: discrepancia, sin stock → requisición de compra, transición
| inválida y autorización menor/mayor a lo solicitado.
*/

beforeEach(function (): void {
    $this->inventario = new RegistrarMovimientoService;
    $this->service = new TransicionarRequisicionService($this->inventario);
    $this->bodega = Bodega::factory()->create();
    $this->bodegaU = Ubicacion::bodega($this->bodega->id);
    $this->proyecto = Proyecto::factory()->create();
});

/**
 * @param array<int, array{item: Item, solicitada: int|string}> $lineas
 */
function crearRequisicion(Proyecto $proyecto, array $lineas): Requisicion
{
    $requisicion = Requisicion::factory()->paraProyecto($proyecto)->create();

    foreach ($lineas as $linea) {
        RequisicionLinea::factory()->create([
            'requisicion_id'      => $requisicion->id,
            'item_id'             => $linea['item']->id,
            'cantidad_solicitada' => $linea['solicitada'],
        ]);
    }

    return $requisicion;
}

function stockEn(int $itemId, int $bodegaId): string
{
    return (string) Existencia::query()
        ->where('item_id', $itemId)
        ->where('bodega_id', $bodegaId)
        ->value('cantidad');
}

function stockObra(int $itemId, int $proyectoId): string
{
    return (string) Existencia::query()
        ->where('item_id', $itemId)
        ->where('proyecto_id', $proyectoId)
        ->value('cantidad');
}

// ─── GOLDEN FLOW ────────────────────────────────────────────────────

test('GOLDEN: flujo completo solicitud → autorización → despacho → recepción → cierre', function (): void {
    $itemA = Item::factory()->create();
    $itemB = Item::factory()->create();

    $this->inventario->entradaCompra($itemA->id, $this->bodegaU, '200', '10');
    $this->inventario->entradaCompra($itemB->id, $this->bodegaU, '100', '5');

    $requisicion = crearRequisicion($this->proyecto, [
        ['item' => $itemA, 'solicitada' => 100],
        ['item' => $itemB, 'solicitada' => 50],
    ]);

    // 1. Autorizar (completo).
    $this->service->autorizar($requisicion);
    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Autorizada);

    // 2. Despachar: mueve stock real bodega → obra con WAC.
    $this->service->despachar($requisicion, $this->bodegaU);
    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Despachada)
        ->and(stockEn($itemA->id, $this->bodega->id))->toBe('100.0000')
        ->and(stockObra($itemA->id, $this->proyecto->id))->toBe('100.0000')
        ->and(stockObra($itemB->id, $this->proyecto->id))->toBe('50.0000');

    // 3. Tránsito.
    $this->service->marcarEnTransito($requisicion);
    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::EnTransito);

    // 4. Recibir (llega completo).
    $this->service->recibir($requisicion);
    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Recibida);

    // 5. Conciliar → cuadra → Cerrada.
    $final = $this->service->conciliar($requisicion);
    expect($final)->toBe(EstadoRequisicion::Cerrada)
        ->and($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Cerrada)
        ->and($requisicion->transiciones()->count())->toBe(5);
});

// ─── Trazabilidad: el movimiento de stock apunta a la requisición ───

test('el despacho deja el movimiento de inventario enlazado a la requisición', function (): void {
    $item = Item::factory()->create();
    $this->inventario->entradaCompra($item->id, $this->bodegaU, '50', '8');
    $requisicion = crearRequisicion($this->proyecto, [['item' => $item, 'solicitada' => 20]]);

    $this->service->autorizar($requisicion);
    $this->service->despachar($requisicion, $this->bodegaU);

    $movimiento = MovimientoInventario::query()
        ->where('referencia_type', $requisicion->getMorphClass())
        ->where('referencia_id', $requisicion->id)
        ->first();

    expect($movimiento)->not->toBeNull()
        ->and($movimiento->item_id)->toBe($item->id)
        ->and($movimiento->cantidad)->toBe('20.0000');
});

// ─── Discrepancia ───────────────────────────────────────────────────

test('si lo recibido no coincide con lo despachado, conciliar marca Discrepancia', function (): void {
    $item = Item::factory()->create();
    $this->inventario->entradaCompra($item->id, $this->bodegaU, '100', '10');
    $requisicion = crearRequisicion($this->proyecto, [['item' => $item, 'solicitada' => 100]]);
    $linea = $requisicion->lineas()->first();

    $this->service->autorizar($requisicion);
    $this->service->despachar($requisicion, $this->bodegaU);
    $this->service->marcarEnTransito($requisicion);

    // Se despacharon 100 pero solo llegaron 90.
    $this->service->recibir($requisicion, [$linea->id => '90']);
    $final = $this->service->conciliar($requisicion);

    expect($final)->toBe(EstadoRequisicion::Discrepancia)
        ->and($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Discrepancia);
});

// ─── Sin stock → Requisición de compra ──────────────────────────────

test('despachar sin stock manda la requisición a RequisicionCompra sin mover nada', function (): void {
    $item = Item::factory()->create(); // sin entrada de stock
    $requisicion = crearRequisicion($this->proyecto, [['item' => $item, 'solicitada' => 10]]);
    $linea = $requisicion->lineas()->first();

    $this->service->autorizar($requisicion);
    $this->service->despachar($requisicion, $this->bodegaU);

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::RequisicionCompra)
        ->and($linea->fresh()->cantidad_despachada)->toBe('0.0000')
        ->and(stockObra($item->id, $this->proyecto->id))->toBe('');

    // Cuando entra el stock, ya se puede despachar.
    $this->inventario->entradaCompra($item->id, $this->bodegaU, '10', '12');
    $this->service->despachar($requisicion, $this->bodegaU);

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Despachada)
        ->and(stockObra($item->id, $this->proyecto->id))->toBe('10.0000');
});

// ─── Autorización parcial ───────────────────────────────────────────

test('se puede autorizar menos de lo solicitado y se despacha esa cantidad', function (): void {
    $item = Item::factory()->create();
    $this->inventario->entradaCompra($item->id, $this->bodegaU, '100', '10');
    $requisicion = crearRequisicion($this->proyecto, [['item' => $item, 'solicitada' => 100]]);
    $linea = $requisicion->lineas()->first();

    $this->service->autorizar($requisicion, [$linea->id => '60']);
    expect($linea->fresh()->cantidad_autorizada)->toBe('60.0000');

    $this->service->despachar($requisicion, $this->bodegaU);
    expect($linea->fresh()->cantidad_despachada)->toBe('60.0000')
        ->and(stockEn($item->id, $this->bodega->id))->toBe('40.0000');
});

test('autorizar más de lo solicitado es rechazado', function (): void {
    $item = Item::factory()->create();
    $requisicion = crearRequisicion($this->proyecto, [['item' => $item, 'solicitada' => 100]]);
    $linea = $requisicion->lineas()->first();

    $this->service->autorizar($requisicion, [$linea->id => '150']);
})->throws(RequisicionInvalidaException::class);

// ─── Máquina de estados ─────────────────────────────────────────────

test('no se puede saltar de Solicitada directo a EnTransito', function (): void {
    $item = Item::factory()->create();
    $requisicion = crearRequisicion($this->proyecto, [['item' => $item, 'solicitada' => 5]]);

    $this->service->marcarEnTransito($requisicion);
})->throws(TransicionInvalidaException::class);

test('rechazar lleva la requisición a estado terminal Rechazada', function (): void {
    $item = Item::factory()->create();
    $requisicion = crearRequisicion($this->proyecto, [['item' => $item, 'solicitada' => 5]]);

    $this->service->rechazar($requisicion, nota: 'proyecto cancelado');

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Rechazada)
        ->and($requisicion->fresh()->estado->esTerminal())->toBeTrue();
});

test('cada transición registra un renglón en la bitácora con su responsable', function (): void {
    $item = Item::factory()->create();
    $this->inventario->entradaCompra($item->id, $this->bodegaU, '50', '10');
    $requisicion = crearRequisicion($this->proyecto, [['item' => $item, 'solicitada' => 20]]);

    $this->service->autorizar($requisicion, userId: null, nota: 'ok ingeniero');

    $transicion = $requisicion->transiciones()->latest('id')->first();

    expect($transicion->estado_origen)->toBe(EstadoRequisicion::Solicitada)
        ->and($transicion->estado_destino)->toBe(EstadoRequisicion::Autorizada)
        ->and($transicion->nota)->toBe('ok ingeniero');
});
