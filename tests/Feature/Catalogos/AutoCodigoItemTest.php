<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;

test('código se genera automáticamente al crear item sin código', function (): void {
    $zona = Zona::factory()->create(['codigo' => 'SRC']);
    $unidad = UnidadMedida::factory()->create();

    $item = Item::create([
        'zona_id'          => $zona->id,
        'unidad_medida_id' => $unidad->id,
        'categoria'        => CategoriaItem::Materiales,
        'nombre'           => 'Cemento gris saco 50kg',
        'precio_unitario'  => 320,
        'activo'           => true,
    ]);

    expect($item->codigo)->toBe('SRC-MAT-00001');
});

test('códigos secuenciales por zona+categoría sin chocar', function (): void {
    $zona = Zona::factory()->create(['codigo' => 'SRC']);
    $unidad = UnidadMedida::factory()->create();

    $datosBase = [
        'zona_id'          => $zona->id,
        'unidad_medida_id' => $unidad->id,
        'precio_unitario'  => 100,
        'activo'           => true,
    ];

    $i1 = Item::create([...$datosBase, 'categoria' => CategoriaItem::Materiales,        'nombre' => 'Material A']);
    $i2 = Item::create([...$datosBase, 'categoria' => CategoriaItem::Materiales,        'nombre' => 'Material B']);
    $i3 = Item::create([...$datosBase, 'categoria' => CategoriaItem::ManoObra,          'nombre' => 'Albañil']);
    $i4 = Item::create([...$datosBase, 'categoria' => CategoriaItem::HerramientaEquipo, 'nombre' => 'Mezcladora']);
    $i5 = Item::create([...$datosBase, 'categoria' => CategoriaItem::Indirectos,        'nombre' => 'Transporte']);

    expect($i1->codigo)->toBe('SRC-MAT-00001');
    expect($i2->codigo)->toBe('SRC-MAT-00002');
    expect($i3->codigo)->toBe('SRC-MO-00001');
    expect($i4->codigo)->toBe('SRC-HE-00001');
    expect($i5->codigo)->toBe('SRC-IND-00001');
});

test('códigos no chocan entre zonas distintas', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $unidad = UnidadMedida::factory()->create();

    $itemSrc = Item::create([
        'zona_id'          => $src->id,
        'unidad_medida_id' => $unidad->id,
        'categoria'        => CategoriaItem::Materiales,
        'nombre'           => 'Cemento Santa Rosa',
        'precio_unitario'  => 320,
        'activo'           => true,
    ]);

    $itemTgu = Item::create([
        'zona_id'          => $tgu->id,
        'unidad_medida_id' => $unidad->id,
        'categoria'        => CategoriaItem::Materiales,
        'nombre'           => 'Cemento Tegucigalpa',
        'precio_unitario'  => 350,
        'activo'           => true,
    ]);

    expect($itemSrc->codigo)->toBe('SRC-MAT-00001');
    expect($itemTgu->codigo)->toBe('TGU-MAT-00001');
});

test('código manualmente provisto se respeta (no se sobrescribe)', function (): void {
    $item = Item::factory()->create(['codigo' => 'CUSTOM-001']);

    expect($item->codigo)->toBe('CUSTOM-001');
});

test('la numeración continúa correctamente después de eliminar items intermedios', function (): void {
    $zona = Zona::factory()->create(['codigo' => 'SRC']);
    $unidad = UnidadMedida::factory()->create();

    $datos = [
        'zona_id'          => $zona->id,
        'unidad_medida_id' => $unidad->id,
        'categoria'        => CategoriaItem::Materiales,
        'precio_unitario'  => 50,
        'activo'           => true,
    ];

    $i1 = Item::create([...$datos, 'nombre' => 'A']); // SRC-MAT-00001
    $i2 = Item::create([...$datos, 'nombre' => 'B']); // SRC-MAT-00002
    $i3 = Item::create([...$datos, 'nombre' => 'C']); // SRC-MAT-00003

    expect($i3->codigo)->toBe('SRC-MAT-00003');

    $i2->delete();

    // El siguiente debe ser 00004 (no reusa el 00002 borrado)
    $i4 = Item::create([...$datos, 'nombre' => 'D']);
    expect($i4->codigo)->toBe('SRC-MAT-00004');
});
