<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;
use App\Enums\OrigenDespacho;
use App\Exceptions\Requisiciones\RequisicionInvalidaException;
use App\Exceptions\Requisiciones\TransicionInvalidaException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\User;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Requisiciones\TransicionarRequisicionService;

/*
|--------------------------------------------------------------------------
| Compra directa a obra: NO pasa por "En tránsito" (bug REQ-2026-00005).
|--------------------------------------------------------------------------
| El 06/08/2026 una requisición se despachó por compra directa ("Despacho
| directo a obra por compra COM-2026-00002") y un minuto después alguien la
| marcó "En tránsito". No debió poder: ese material nunca salió de una
| bodega nuestra — lo dejó el proveedor en la obra.
|
| La regla: el origen del despacho decide el final del flujo.
|   bodega         → Despachada → EnTransito → Recibida
|   compra_directa → Despachada ─────────────→ Recibida
*/

beforeEach(function (): void {
    $this->transiciones = app(TransicionarRequisicionService::class);
    $this->compras = app(ConfirmarCompraService::class);
    $this->proyecto = Proyecto::factory()->create();
    $this->material = Material::factory()->create();
});

/**
 * Requisición esperando compra, con una línea autorizada por $cantidad.
 */
function requisicionEsperandoCompra(Proyecto $proyecto, Material $material, string $cantidad = '50.0000'): Requisicion
{
    $requisicion = Requisicion::factory()
        ->paraProyecto($proyecto)
        ->enEstado(EstadoRequisicion::RequisicionCompra)
        ->create();

    RequisicionLinea::factory()->paraMaterial($material)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => $cantidad,
        'cantidad_autorizada' => $cantidad,
    ]);

    return $requisicion;
}

/**
 * Confirma una compra directa a obra que cubre $cantidad del material.
 */
function confirmarCompraDirecta(
    Proyecto $proyecto,
    Material $material,
    Requisicion $requisicion,
    string $cantidad = '50',
    ?int $userId = null,
): Compra {
    $compra = Compra::factory()
        ->directaAObra($proyecto)
        ->paraRequisicion($requisicion)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);

    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => $material->id,
        'cantidad'       => $cantidad,
        'costo_unitario' => 10,
    ]);

    app(ConfirmarCompraService::class)->confirmar($compra, $userId);

    return $compra;
}

test('GOLDEN: el despacho por compra directa marca el origen y cierra el paso al tránsito', function (): void {
    $requisicion = requisicionEsperandoCompra($this->proyecto, $this->material);

    confirmarCompraDirecta($this->proyecto, $this->material, $requisicion);

    $requisicion->refresh();

    expect($requisicion->estado)->toBe(EstadoRequisicion::Despachada)
        ->and($requisicion->origen_despacho)->toBe(OrigenDespacho::CompraDirecta)
        ->and($requisicion->esDespachoDirecto())->toBeTrue()
        // El único camino que queda es la confirmación de la obra.
        ->and($requisicion->transicionesPermitidas())->toBe([EstadoRequisicion::Recibida])
        ->and($requisicion->puedeTransicionarA(EstadoRequisicion::EnTransito))->toBeFalse();
});

test('marcar en tránsito una compra directa se rechaza con un mensaje que se entiende', function (): void {
    $requisicion = requisicionEsperandoCompra($this->proyecto, $this->material);
    confirmarCompraDirecta($this->proyecto, $this->material, $requisicion);

    expect(fn () => $this->transiciones->marcarEnTransito($requisicion->refresh()))
        ->toThrow(RequisicionInvalidaException::class, 'no puede marcarse en tránsito');

    expect($requisicion->refresh()->estado)->toBe(EstadoRequisicion::Despachada);
});

test('la compra directa va derecho a Recibida sin pasar por tránsito', function (): void {
    $requisicion = requisicionEsperandoCompra($this->proyecto, $this->material);
    confirmarCompraDirecta($this->proyecto, $this->material, $requisicion);

    $this->transiciones->recibir($requisicion->refresh());

    expect($requisicion->refresh()->estado)->toBe(EstadoRequisicion::Recibida)
        ->and($requisicion->transiciones()->where('estado_destino', EstadoRequisicion::EnTransito->value)->exists())
        ->toBeFalse();
});

test('la vía bodega NO cambia: sigue exigiendo el tránsito antes de recibir', function (): void {
    $bodega = Bodega::factory()->create();

    $requisicion = Requisicion::factory()
        ->paraProyecto($this->proyecto)
        ->enEstado(EstadoRequisicion::Autorizada)
        ->create();
    RequisicionLinea::factory()->paraMaterial($this->material)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '10.0000',
        'cantidad_autorizada' => '10.0000',
    ]);

    app(RegistrarMovimientoService::class)->entradaCompra(
        materialId: $this->material->id,
        destino: Ubicacion::bodega($bodega->id),
        cantidad: '10',
        costoUnitario: '5',
    );

    $this->transiciones->despachar($requisicion, Ubicacion::bodega($bodega->id));
    $requisicion->refresh();

    expect($requisicion->origen_despacho)->toBe(OrigenDespacho::Bodega)
        ->and($requisicion->transicionesPermitidas())->toBe([EstadoRequisicion::EnTransito]);

    // Saltarse el tránsito en la vía bodega sigue siendo inválido.
    expect(fn () => $this->transiciones->recibir($requisicion))
        ->toThrow(TransicionInvalidaException::class);
});

test('B3: si el que verificó la compra es el encargado de la obra, la requisición se recibe y cierra sola', function (): void {
    $encargado = User::factory()->create(['is_active' => true]);
    $this->proyecto->encargados()->attach($encargado->id);

    $requisicion = requisicionEsperandoCompra($this->proyecto, $this->material);

    confirmarCompraDirecta($this->proyecto, $this->material, $requisicion, userId: $encargado->id);

    $requisicion->refresh();

    // Ya contó el material en el sitio contra la factura: no se le pide
    // contarlo otra vez el mismo día.
    expect($requisicion->estado)->toBe(EstadoRequisicion::Cerrada)
        ->and($requisicion->lineas()->firstOrFail()->cantidad_recibida)->toBe('50.0000');
});

test('B3: si la compra la verificó la oficina, la requisición queda esperando que la obra confirme', function (): void {
    $oficina = User::factory()->create(['is_active' => true]); // NO es encargado de la obra

    $requisicion = requisicionEsperandoCompra($this->proyecto, $this->material);

    confirmarCompraDirecta($this->proyecto, $this->material, $requisicion, userId: $oficina->id);

    // Nadie en la obra vio ese material: la confirmación sigue pendiente.
    // Es exactamente lo que pasó en REQ-2026-00005.
    expect($requisicion->refresh()->estado)->toBe(EstadoRequisicion::Despachada);
});

test('B3: una compra PARCIAL no se auto-recibe, aunque la verifique el encargado', function (): void {
    $encargado = User::factory()->create(['is_active' => true]);
    $this->proyecto->encargados()->attach($encargado->id);

    $requisicion = requisicionEsperandoCompra($this->proyecto, $this->material, '100.0000');

    // La compra solo cubre 30 de los 100 autorizados.
    confirmarCompraDirecta($this->proyecto, $this->material, $requisicion, cantidad: '30', userId: $encargado->id);

    $requisicion->refresh();

    expect($requisicion->estado)->toBe(EstadoRequisicion::Despachada)
        ->and($requisicion->lineas()->firstOrFail()->cantidad_despachada)->toBe('30.0000');
});
