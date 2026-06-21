<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\Material;
use App\Models\Zona;

/*
|--------------------------------------------------------------------------
| Tests del catálogo de Materiales (recurso físico de inventario, ADR-0003).
|--------------------------------------------------------------------------
| El material es ÚNICO y GLOBAL: el cemento es uno solo, no se duplica por
| zona. La base de precios (Item) sí es por zona y se enlaza al material vía
| items.material_id. Estos tests blindan esa separación y la deduplicación
| que arregla el bug del selector "Registrar entrada".
*/

test('el código de material se autogenera global y secuencial por categoría', function (): void {
    $m1 = Material::factory()->deCategoria(CategoriaItem::Materiales)->create();
    $m2 = Material::factory()->deCategoria(CategoriaItem::Materiales)->create();
    $h1 = Material::factory()->deCategoria(CategoriaItem::HerramientaEquipo)->create();

    expect($m1->codigo)->toBe('MAT-00001')
        ->and($m2->codigo)->toBe('MAT-00002')
        ->and($h1->codigo)->toBe('HE-00001');
});

test('el código de material es global: NO lleva prefijo de zona', function (): void {
    $material = Material::factory()->create();

    expect($material->codigo)->toStartWith('MAT-')
        ->and($material->codigo)->not->toContain('SRC')
        ->and($material->codigo)->not->toContain('TGU');
});

test('el nombre del material se normaliza a mayúsculas', function (): void {
    $material = Material::factory()->create(['nombre' => 'cemento gris 42.5kg']);

    expect($material->nombre)->toBe('CEMENTO GRIS 42.5KG');
});

test('no se puede crear un material de categoría no inventariable (mano de obra)', function (): void {
    expect(fn (): Material => Material::factory()->deCategoria(CategoriaItem::ManoObra)->create())
        ->toThrow(InvalidArgumentException::class);
});

test('varios items de distintas zonas comparten el MISMO material físico', function (): void {
    $material = Material::factory()->create(['nombre' => 'CEMENTO GRIS 42.5KG']);
    $zonaSrc = Zona::factory()->create(['codigo' => 'SRC']);
    $zonaTgu = Zona::factory()->create(['codigo' => 'TGU']);

    $itemSrc = Item::factory()->enZona($zonaSrc)->deCategoria(CategoriaItem::Materiales)
        ->create(['material_id' => $material->id, 'precio_unitario' => 250]);
    $itemTgu = Item::factory()->enZona($zonaTgu)->deCategoria(CategoriaItem::Materiales)
        ->create(['material_id' => $material->id, 'precio_unitario' => 265]);

    // Mismo material físico, distinto precio de venta por zona.
    expect($material->items()->count())->toBe(2)
        ->and($itemSrc->material->is($material))->toBeTrue()
        ->and($itemTgu->material->is($material))->toBeTrue()
        ->and($itemSrc->precio_unitario)->not->toBe($itemTgu->precio_unitario);
});

test('el catálogo no duplica el material aunque tenga precio en varias zonas (fix del selector)', function (): void {
    $material = Material::factory()->create();

    foreach (['SRC', 'TGU', 'DLC'] as $codigoZona) {
        $zona = Zona::factory()->create(['codigo' => $codigoZona]);
        Item::factory()->enZona($zona)->deCategoria(CategoriaItem::Materiales)
            ->create(['material_id' => $material->id]);
    }

    // El selector de "Registrar entrada" lista materiales: aquí hay UNO solo,
    // aunque existan 3 items de precio (uno por zona) apuntando a él.
    expect(Material::count())->toBe(1)
        ->and(Item::where('material_id', $material->id)->count())->toBe(3);
});

test('un item no inventariable (mano de obra) puede no tener material', function (): void {
    $item = Item::factory()->deCategoria(CategoriaItem::ManoObra)->create(['material_id' => null]);

    expect($item->material_id)->toBeNull()
        ->and($item->material)->toBeNull();
});
