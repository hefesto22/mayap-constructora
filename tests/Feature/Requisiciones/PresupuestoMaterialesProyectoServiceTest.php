<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Enums\EstadoRequisicion;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\Zona;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Requisiciones\PresupuestoMaterialesProyectoService;

/**
 * Escenario base: proyecto con 1 renglón de 100 unidades de una ficha
 * cuya línea consume 3 bolsas de cemento por unidad → presupuestado 300.
 *
 * @return array{0: Proyecto, 1: Material}
 */
function montarObraConCemento(): array
{
    $zona = Zona::factory()->create();
    $proyecto = Proyecto::factory()->create(['zona_id' => $zona->id]);

    $cemento = Material::factory()->create(['nombre' => 'CEMENTO GRIS']);
    $item = Item::factory()
        ->enZona($zona)
        ->deCategoria(CategoriaItem::Materiales)
        ->conMaterial($cemento)
        ->create();

    $ficha = Ficha::factory()->create(['zona_id' => $zona->id]);
    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($item)
        ->conRendimiento('3.000000')
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($ficha)
        ->conCantidad('100.0000', '500.00')
        ->create();

    return [$proyecto, $cemento];
}

test('calcula el presupuestado desde las fichas: cantidad renglón × rendimiento', function () {
    [$proyecto, $cemento] = montarObraConCemento();

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyecto->id, $cemento->id);

    expect($pm)->not->toBeNull()
        ->and($pm->presupuestado)->toBe('300.0000')
        ->and($pm->solicitado)->toBe('0.0000')
        ->and($pm->disponible())->toBe('300.0000')
        ->and($pm->excedido())->toBeFalse();
});

test('resta lo solicitado en requisiciones del disponible', function () {
    [$proyecto, $cemento] = montarObraConCemento();

    $req = Requisicion::factory()->paraProyecto($proyecto)->create();
    RequisicionLinea::factory()
        ->paraMaterial($cemento)
        ->create(['requisicion_id' => $req->id, 'cantidad_solicitada' => '50.0000']);

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyecto->id, $cemento->id);

    expect($pm->solicitado)->toBe('50.0000')
        ->and($pm->disponible())->toBe('250.0000')
        ->and($pm->excedido())->toBeFalse();
});

test('la cantidad autorizada manda sobre la solicitada cuando existe', function () {
    [$proyecto, $cemento] = montarObraConCemento();

    $req = Requisicion::factory()->paraProyecto($proyecto)->create();
    RequisicionLinea::factory()
        ->paraMaterial($cemento)
        ->create([
            'requisicion_id'      => $req->id,
            'cantidad_solicitada' => '80.0000',
            'cantidad_autorizada' => '60.0000',
        ]);

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyecto->id, $cemento->id);

    expect($pm->solicitado)->toBe('60.0000')
        ->and($pm->disponible())->toBe('240.0000');
});

test('detecta el exceso cuando lo solicitado supera lo presupuestado', function () {
    [$proyecto, $cemento] = montarObraConCemento();

    $req = Requisicion::factory()->paraProyecto($proyecto)->create();
    RequisicionLinea::factory()
        ->paraMaterial($cemento)
        ->create(['requisicion_id' => $req->id, 'cantidad_solicitada' => '350.0000']);

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyecto->id, $cemento->id);

    expect($pm->excedido())->toBeTrue()
        ->and($pm->exceso())->toBe('50.0000')
        ->and($pm->disponible())->toBe('-50.0000');
});

test('las requisiciones rechazadas NO cuentan contra el presupuesto', function () {
    [$proyecto, $cemento] = montarObraConCemento();

    $rechazada = Requisicion::factory()
        ->paraProyecto($proyecto)
        ->enEstado(EstadoRequisicion::Rechazada)
        ->create();
    RequisicionLinea::factory()
        ->paraMaterial($cemento)
        ->create(['requisicion_id' => $rechazada->id, 'cantidad_solicitada' => '200.0000']);

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyecto->id, $cemento->id);

    expect($pm->solicitado)->toBe('0.0000')
        ->and($pm->disponible())->toBe('300.0000');
});

test('material pedido pero NO presupuestado aparece como fuera de presupuesto', function () {
    [$proyecto] = montarObraConCemento();

    $arena = Material::factory()->create(['nombre' => 'ARENA DE RÍO']);
    $req = Requisicion::factory()->paraProyecto($proyecto)->create();
    RequisicionLinea::factory()
        ->paraMaterial($arena)
        ->create(['requisicion_id' => $req->id, 'cantidad_solicitada' => '10.0000']);

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyecto->id, $arena->id);

    expect($pm)->not->toBeNull()
        ->and($pm->presupuestado)->toBe('0.0000')
        ->and($pm->excedido())->toBeTrue()
        ->and($pm->porcentajeComprometido())->toBe('999.99');
});

test('suma múltiples renglones y múltiples requisiciones del mismo material', function () {
    [$proyecto, $cemento] = montarObraConCemento();

    // Segundo renglón: 50 unidades × misma ficha (3/u) = +150 presupuestado.
    $renglon = ProyectoRenglon::query()->where('proyecto_id', $proyecto->id)->firstOrFail();
    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($renglon->ficha)
        ->conCantidad('50.0000', '500.00')
        ->create();

    foreach (['20.0000', '30.0000'] as $cantidad) {
        $req = Requisicion::factory()->paraProyecto($proyecto)->create();
        RequisicionLinea::factory()
            ->paraMaterial($cemento)
            ->create(['requisicion_id' => $req->id, 'cantidad_solicitada' => $cantidad]);
    }

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyecto->id, $cemento->id);

    expect($pm->presupuestado)->toBe('450.0000')
        ->and($pm->solicitado)->toBe('50.0000')
        ->and($pm->disponible())->toBe('400.0000');
});

test('herramienta y equipo NO entra al presupuesto de materiales (va por Maquinaria)', function () {
    [$proyecto] = montarObraConCemento();

    // La misma ficha del proyecto consume una retroexcavadora (equipo).
    $retro = Material::factory()
        ->deCategoria(CategoriaItem::HerramientaEquipo)
        ->create(['nombre' => 'RETROEXCAVADORA']);

    $renglon = ProyectoRenglon::query()->where('proyecto_id', $proyecto->id)->firstOrFail();
    $itemRetro = Item::factory()
        ->enZona($renglon->proyecto->zona)
        ->deCategoria(CategoriaItem::HerramientaEquipo)
        ->conMaterial($retro)
        ->create();
    FichaLinea::factory()
        ->paraFicha($renglon->ficha)
        ->conItem($itemRetro)
        ->conRendimiento('0.500000')
        ->create();

    $presupuesto = app(PresupuestoMaterialesProyectoService::class)
        ->porProyecto($proyecto->id);

    expect($presupuesto->has($retro->id))->toBeFalse();
});

test('la compra directa a obra SIN requisición cuenta contra el presupuesto', function () {
    [$proyecto, $cemento] = montarObraConCemento();

    // 40 bolsas compradas directo a la obra, sin requisición previa.
    $compra = Compra::factory()
        ->directaAObra($proyecto)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $cemento->id,
        'cantidad'  => 40, 'costo_unitario' => 220,
    ]);
    app(ConfirmarCompraService::class)->confirmar($compra);

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyecto->id, $cemento->id);

    // Presupuestado 300 − comprado directo 40 = quedan 260.
    expect($pm->solicitado)->toBe('40.0000')
        ->and($pm->despachado)->toBe('40.0000')
        ->and($pm->disponible())->toBe('260.0000');
});

test('la compra directa CON requisición no duplica el conteo', function () {
    [$proyecto, $cemento] = montarObraConCemento();

    // Requisición de 50 autorizada…
    $requisicion = Requisicion::factory()
        ->paraProyecto($proyecto)
        ->enEstado(EstadoRequisicion::RequisicionCompra)
        ->create();
    RequisicionLinea::factory()->paraMaterial($cemento)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '50.0000',
        'cantidad_autorizada' => '50.0000',
    ]);

    // …resuelta con compra directa enlazada de las mismas 50.
    $compra = Compra::factory()
        ->directaAObra($proyecto)
        ->paraRequisicion($requisicion)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $cemento->id,
        'cantidad'  => 50, 'costo_unitario' => 220,
    ]);
    app(ConfirmarCompraService::class)->confirmar($compra);

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyecto->id, $cemento->id);

    // Cuenta UNA vez (vía la requisición), no 100: disponible 300 − 50 = 250.
    expect($pm->solicitado)->toBe('50.0000')
        ->and($pm->disponible())->toBe('250.0000');
});

test('no mezcla materiales entre proyectos distintos', function () {
    [$proyectoA, $cemento] = montarObraConCemento();
    $proyectoB = Proyecto::factory()->create();

    $req = Requisicion::factory()->paraProyecto($proyectoB)->create();
    RequisicionLinea::factory()
        ->paraMaterial($cemento)
        ->create(['requisicion_id' => $req->id, 'cantidad_solicitada' => '999.0000']);

    $pm = app(PresupuestoMaterialesProyectoService::class)
        ->paraMaterial($proyectoA->id, $cemento->id);

    expect($pm->solicitado)->toBe('0.0000');
});
