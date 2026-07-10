<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Enums\EstadoCompra;
use App\Enums\EstadoProyecto;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Models\User;
use App\Models\Zona;
use App\Services\Compras\MarcarPorRecibirService;
use App\Support\Permisos;
use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Reglas de destino a OBRA al registrar una compra:
|--------------------------------------------------------------------------
| 1. Solo obras VIVAS (En ejecución / Pausada) reciben material — duro.
| 2. Material NO presupuestado en la obra → bloqueado, salvo permiso
|    "Comprar fuera de presupuesto" (imprevistos autorizados).
*/

beforeEach(function (): void {
    Permission::findOrCreate(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO, 'web');
    $this->registrar = app(MarcarPorRecibirService::class);
});

/**
 * Obra EN EJECUCIÓN cuyo presupuesto contempla 300 bolsas de cemento.
 *
 * @return array{0: Proyecto, 1: Material}
 */
function montarObraVivaConCemento(): array
{
    $zona = Zona::factory()->create();
    $proyecto = Proyecto::factory()->enEjecucion()->create(['zona_id' => $zona->id]);

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

function compraDirecta(Proyecto $obra, Material $material): Compra
{
    $compra = Compra::factory()->directaAObra($obra)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 10, 'costo_unitario' => 100,
    ]);

    return $compra;
}

test('permite comprar a obra viva un material presupuestado (flujo normal)', function (): void {
    [$obra, $cemento] = montarObraVivaConCemento();

    $compra = compraDirecta($obra, $cemento);
    $this->registrar->registrar($compra);

    expect($compra->fresh()->estado)->toBe(EstadoCompra::PorRecibir);
});

test('bloquea enviar material a una obra terminada, cancelada o sin iniciar', function (): void {
    [$obra, $cemento] = montarObraVivaConCemento();

    // Terminada: ni con permiso — bloqueo duro.
    $obra->update(['estado' => EstadoProyecto::Finalizada->value, 'fecha_fin_real' => now()]);

    $autorizado = User::factory()->create(['is_active' => true]);
    $autorizado->givePermissionTo(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO);

    $compra = compraDirecta($obra->fresh(), $cemento);

    expect(fn () => $this->registrar->registrar($compra, $autorizado->id))
        ->toThrow(CompraNoConfirmableException::class, 'Solo las obras EN EJECUCIÓN o PAUSADAS');

    // Cotización en borrador: tampoco (nunca ha iniciado).
    $cotizacion = Proyecto::factory()->create();
    $compra2 = compraDirecta($cotizacion, $cemento);

    expect(fn () => $this->registrar->registrar($compra2, $autorizado->id))
        ->toThrow(CompraNoConfirmableException::class);
});

test('bloquea material NO presupuestado en la obra si no se tiene el permiso', function (): void {
    [$obra] = montarObraVivaConCemento();

    // Varilla #4: la obra no la contempla en ninguna ficha.
    $varilla = Material::factory()->create(['nombre' => 'VARILLA #4']);
    $compra = compraDirecta($obra, $varilla);

    expect(fn () => $this->registrar->registrar($compra))
        ->toThrow(CompraNoConfirmableException::class, 'NO está en el presupuesto');
});

test('el permiso "Comprar fuera de presupuesto" autoriza el imprevisto', function (): void {
    [$obra] = montarObraVivaConCemento();

    $varilla = Material::factory()->create(['nombre' => 'VARILLA #4']);
    $compra = compraDirecta($obra, $varilla);

    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->givePermissionTo(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO);

    $this->registrar->registrar($compra, $gerente->id);

    expect($compra->fresh()->estado)->toBe(EstadoCompra::PorRecibir);
});

test('las compras a bodega no pasan por estas reglas (el presupuesto es por obra)', function (): void {
    $bodega = Bodega::factory()->create();
    $material = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($bodega)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 10, 'costo_unitario' => 100,
    ]);

    $this->registrar->registrar($compra);

    expect($compra->fresh()->estado)->toBe(EstadoCompra::PorRecibir);
});
