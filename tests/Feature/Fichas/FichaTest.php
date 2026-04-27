<?php

declare(strict_types=1);

use App\Enums\CategoriaBaseLinea;
use App\Enums\CategoriaItem;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use Illuminate\Database\QueryException;

test('ficha persiste con casts correctos', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();

    $ficha = Ficha::factory()
        ->enZona($zona)
        ->conUnidad($unidad)
        ->create([
            'utilidad_porcentaje' => 30.50,
            'parametros_tecnicos' => ['VOLUMEN' => '0.1', 'ESPESOR' => '10CM'],
        ]);

    $fresh = $ficha->fresh();

    expect($fresh)->not->toBeNull();
    expect($fresh->utilidad_porcentaje)->toBe('30.50');
    // toEqual (no toBe): JSONB de Postgres NO preserva el orden de claves
    // al persistir y deserializar. Comparamos contenido, no orden.
    expect($fresh->parametros_tecnicos)->toEqual(['VOLUMEN' => '0.1', 'ESPESOR' => '10CM']);
    expect($fresh->activa)->toBeTrue();
});

test('código de ficha es único POR zona, NO global', function (): void {
    $srcZona = Zona::factory()->create(['codigo' => 'SRC']);
    $tguZona = Zona::factory()->create(['codigo' => 'TGU']);

    Ficha::factory()->enZona($srcZona)->create(['codigo' => 'CUSTOM-001']);

    $fichaTgu = Ficha::factory()->enZona($tguZona)->create(['codigo' => 'CUSTOM-001']);
    expect($fichaTgu)->toBeInstanceOf(Ficha::class);

    expect(static fn () => Ficha::factory()->enZona($srcZona)->create(['codigo' => 'CUSTOM-001']))
        ->toThrow(QueryException::class);
});

test('utilidad negativa es rechazada por CHECK constraint de Postgres', function (): void {
    expect(static fn () => Ficha::factory()->create(['utilidad_porcentaje' => -5]))
        ->toThrow(QueryException::class);
});

test('relaciones zona y unidadMedida cargan correctamente', function (): void {
    $zona = Zona::factory()->create(['nombre' => 'Santa Rosa']);
    $unidad = UnidadMedida::factory()->create(['codigo' => 'M2']);

    $ficha = Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    expect($ficha->zona->nombre)->toBe('SANTA ROSA');
    expect($ficha->unidadMedida->codigo)->toBe('M2');
});

test('relación lineas retorna líneas ordenadas por orden', function (): void {
    $zona = Zona::factory()->create(['codigo' => 'SRC']);
    $unidad = UnidadMedida::factory()->create();
    $itemA = Item::factory()->enZona($zona)->conUnidad($unidad)->create();
    $itemB = Item::factory()->enZona($zona)->conUnidad($unidad)->create();

    $ficha = Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    FichaLinea::factory()->paraFicha($ficha)->conItem($itemB)
        ->create(['orden' => 5]);
    FichaLinea::factory()->paraFicha($ficha)->conItem($itemA)
        ->create(['orden' => 1]);

    $lineas = $ficha->lineas;

    expect($lineas)->toHaveCount(2);
    expect($lineas->first()->orden)->toBe(1);
    expect($lineas->last()->orden)->toBe(5);
});

test('scope activas filtra solo fichas con flag true', function (): void {
    Ficha::factory()->count(3)->create();
    Ficha::factory()->inactiva()->count(2)->create();

    expect(Ficha::activas()->count())->toBe(3);
    expect(Ficha::count())->toBe(5);
});

test('scope deZona filtra fichas de la zona indicada', function (): void {
    $src = Zona::factory()->create();
    $tgu = Zona::factory()->create();

    Ficha::factory()->count(4)->enZona($src)->create();
    Ficha::factory()->count(2)->enZona($tgu)->create();

    expect(Ficha::deZona($src->id)->count())->toBe(4);
    expect(Ficha::deZona($tgu->id)->count())->toBe(2);
});

test('FichaLinea tipo=item carga su item con relación', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();
    $item = Item::factory()->enZona($zona)->conUnidad($unidad)->create();
    $ficha = Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    $linea = FichaLinea::factory()->paraFicha($ficha)->conItem($item)->create();

    expect($linea->esItem())->toBeTrue();
    expect($linea->esPorcentaje())->toBeFalse();
    expect($linea->item->id)->toBe($item->id);
});

test('FichaLinea tipo=porcentaje no tiene item', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();
    $ficha = Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    $linea = FichaLinea::factory()
        ->paraFicha($ficha)
        ->porcentaje(
            'HERRAMIENTA MENOR',
            5.00,
            CategoriaBaseLinea::ManoObra,
            CategoriaItem::HerramientaEquipo
        )
        ->create();

    expect($linea->esItem())->toBeFalse();
    expect($linea->esPorcentaje())->toBeTrue();
    expect($linea->item)->toBeNull();
    expect($linea->categoria_base)->toBe(CategoriaBaseLinea::ManoObra);
    expect($linea->categoria_destino)->toBe(CategoriaItem::HerramientaEquipo);
});

test('seccionDelReporte deriva de la categoría del item para tipo=item', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();
    $item = Item::factory()
        ->enZona($zona)
        ->conUnidad($unidad)
        ->deCategoria(CategoriaItem::ManoObra)
        ->create();
    $ficha = Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    $linea = FichaLinea::factory()->paraFicha($ficha)->conItem($item)->create();

    expect($linea->seccionDelReporte())->toBe(CategoriaItem::ManoObra);
});

test('seccionDelReporte usa categoria_destino para tipo=porcentaje', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();
    $ficha = Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    $linea = FichaLinea::factory()
        ->paraFicha($ficha)
        ->porcentaje(
            'IMPREVISTOS',
            3.00,
            CategoriaBaseLinea::CostoDirecto,
            CategoriaItem::Indirectos
        )
        ->create();

    expect($linea->seccionDelReporte())->toBe(CategoriaItem::Indirectos);
});

test('cuando se elimina la ficha sus líneas se eliminan en cascada', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();
    $ficha = Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    // 3 items DISTINTOS — el unique(ficha_id, item_id) prohíbe duplicar item en una ficha.
    $items = Item::factory()->count(3)->enZona($zona)->conUnidad($unidad)->create();

    foreach ($items as $item) {
        FichaLinea::factory()->paraFicha($ficha)->conItem($item)->create();
    }

    expect(FichaLinea::where('ficha_id', $ficha->id)->count())->toBe(3);

    $ficha->delete();

    expect(FichaLinea::where('ficha_id', $ficha->id)->count())->toBe(0);
});

test('FK restrict: NO se elimina un item que está usado en alguna ficha', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();
    $item = Item::factory()->enZona($zona)->conUnidad($unidad)->create();
    $ficha = Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    FichaLinea::factory()->paraFicha($ficha)->conItem($item)->create();

    expect(static fn () => $item->delete())
        ->toThrow(QueryException::class);
});

test('FK restrict: NO se elimina una zona que tiene fichas', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();
    Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    expect(static fn () => $zona->delete())
        ->toThrow(QueryException::class);
});

test('mutator uppercase aplica a nombre y descripción de Ficha', function (): void {
    $ficha = Ficha::factory()->create([
        'nombre'      => 'losa de concreto',
        'descripcion' => 'descripción larga con detalle',
    ]);

    $fresh = $ficha->fresh();

    expect($fresh->nombre)->toBe('LOSA DE CONCRETO');
    expect($fresh->descripcion)->toBe('DESCRIPCIÓN LARGA CON DETALLE');
});

test('mutator uppercase aplica a descripcion y notas de FichaLinea', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();
    $ficha = Ficha::factory()->enZona($zona)->conUnidad($unidad)->create();

    $linea = FichaLinea::factory()
        ->paraFicha($ficha)
        ->porcentaje(
            'herramienta menor',
            5.00,
            CategoriaBaseLinea::ManoObra,
            CategoriaItem::HerramientaEquipo
        )
        ->create(['notas' => 'aplicar siempre en losas']);

    $fresh = $linea->fresh();

    expect($fresh->descripcion)->toBe('HERRAMIENTA MENOR');
    expect($fresh->notas)->toBe('APLICAR SIEMPRE EN LOSAS');
});
