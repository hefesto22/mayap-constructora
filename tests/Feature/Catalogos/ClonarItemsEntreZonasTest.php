<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Catalogos\ClonarItemsEntreZonas;
use Spatie\Activitylog\Models\Activity;

test('clona todos los items activos a la zona destino con códigos auto', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $unidad = UnidadMedida::factory()->create();

    Item::factory()->count(3)->enZona($src)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::Materiales)->create();
    Item::factory()->count(2)->enZona($src)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::ManoObra)->create();

    $resultado = app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu);

    expect($resultado['clonados'])->toBe(5);
    expect($resultado['omitidos'])->toBe(0);

    expect($tgu->items()->count())->toBe(5);

    // Verificar que los códigos se regeneraron en el patrón TGU
    $codigosTgu = $tgu->items()->pluck('codigo')->all();

    foreach ($codigosTgu as $codigo) {
        expect($codigo)->toStartWith('TGU-');
    }
});

test('NO clona items inactivos de la zona origen', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $unidad = UnidadMedida::factory()->create();

    Item::factory()->count(2)->enZona($src)->conUnidad($unidad)->create();
    Item::factory()->enZona($src)->conUnidad($unidad)->inactivo()->create();

    $resultado = app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu);

    expect($resultado['clonados'])->toBe(2);
    expect($tgu->items()->count())->toBe(2);
});

test('omite items duplicados por nombre+categoría cuando saltarDuplicados=true', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $unidad = UnidadMedida::factory()->create();

    // En SRC: Cemento Argos (Materiales) + Albañil (ManoObra)
    Item::factory()->enZona($src)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::Materiales)
        ->create(['nombre' => 'Cemento Argos']);
    Item::factory()->enZona($src)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::ManoObra)
        ->create(['nombre' => 'Albañil']);

    // En TGU ya existe Cemento Argos en Materiales (preexistente)
    Item::factory()->enZona($tgu)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::Materiales)
        ->create(['nombre' => 'Cemento Argos']);

    $resultado = app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu, saltarDuplicados: true);

    expect($resultado['clonados'])->toBe(1);  // Solo Albañil
    expect($resultado['omitidos'])->toBe(1);  // Cemento Argos omitido
    expect($tgu->items()->count())->toBe(2);  // El preexistente + Albañil
});

test('mismo nombre en distintas categorías son items distintos (NO se omiten)', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $unidad = UnidadMedida::factory()->create();

    // En SRC: "FLETE" como Indirecto y "FLETE" como ManoObra (caso real)
    Item::factory()->enZona($src)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::Indirectos)
        ->create(['nombre' => 'Flete']);
    Item::factory()->enZona($src)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::ManoObra)
        ->create(['nombre' => 'Flete']);

    // En TGU ya existe "FLETE" como Indirecto
    Item::factory()->enZona($tgu)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::Indirectos)
        ->create(['nombre' => 'Flete']);

    $resultado = app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu);

    // Solo se omite el FLETE/Indirecto. El FLETE/ManoObra SÍ se clona
    // porque la firma incluye categoría.
    expect($resultado['clonados'])->toBe(1);
    expect($resultado['omitidos'])->toBe(1);
});

test('preserva precio, descripción, observaciones y unidad', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $unidad = UnidadMedida::factory()->create();

    Item::factory()->enZona($src)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::Materiales)
        ->create([
            'nombre'               => 'Cemento Argos',
            'descripcion'          => 'Saco de 50 kg',
            'precio_unitario'      => 320.50,
            'observaciones_precio' => 'Incluye flete a obra',
        ]);

    app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu);

    $clonado = $tgu->items()->first();

    expect($clonado->nombre)->toBe('CEMENTO ARGOS');
    expect($clonado->descripcion)->toBe('SACO DE 50 KG');
    expect((float) $clonado->precio_unitario)->toBe(320.50);
    expect($clonado->observaciones_precio)->toBe('INCLUYE FLETE A OBRA');
    expect($clonado->unidad_medida_id)->toBe($unidad->id);
    expect($clonado->categoria)->toBe(CategoriaItem::Materiales);
});

test('items clonados son independientes — editar destino no afecta origen', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $unidad = UnidadMedida::factory()->create();

    $itemSrc = Item::factory()->enZona($src)->conUnidad($unidad)
        ->create(['precio_unitario' => 100]);

    app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu);

    $itemTgu = $tgu->items()->first();
    $itemTgu->update(['precio_unitario' => 999]);

    expect($itemSrc->fresh()->precio_unitario)->toBe('100.00');
    expect($itemTgu->fresh()->precio_unitario)->toBe('999.00');
});

test('lanza DomainException si origen y destino son la misma zona', function (): void {
    $zona = Zona::factory()->create();

    expect(static fn () => app(ClonarItemsEntreZonas::class)->ejecutar($zona, $zona))
        ->toThrow(DomainException::class);
});

test('registra entrada semántica en activitylog tras clonar exitosamente', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC', 'nombre' => 'Santa Rosa']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU', 'nombre' => 'Tegucigalpa']);
    $unidad = UnidadMedida::factory()->create();

    Item::factory()->count(3)->enZona($src)->conUnidad($unidad)->create();

    app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu);

    $auditLog = Activity::where('log_name', 'clonado_items')->latest()->first();

    expect($auditLog)->not->toBeNull();
    // Los nombres se persisten uppercase por el mutator del modelo Zona.
    expect($auditLog->description)->toContain('SANTA ROSA');
    expect($auditLog->description)->toContain('TEGUCIGALPA');
    expect($auditLog->properties['clonados'])->toBe(3);
    expect($auditLog->properties['omitidos'])->toBe(0);
    expect($auditLog->properties['origen_codigo'])->toBe('SRC');
    expect($auditLog->properties['destino_codigo'])->toBe('TGU');
    expect($auditLog->properties['ids_clonados'])->toHaveCount(3);
});

test('NO registra auditoría si el clonado no produjo nada', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);

    // SRC sin items activos
    app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu);

    expect(Activity::where('log_name', 'clonado_items')->count())->toBe(0);
});

test('totalClonadosHaciaZona cuenta operaciones de clonado a una zona específica', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $sps = Zona::factory()->create(['codigo' => 'SPS']);
    $unidad = UnidadMedida::factory()->create();

    Item::factory()->count(2)->enZona($src)->conUnidad($unidad)->create();

    app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu);
    app(ClonarItemsEntreZonas::class)->ejecutar($src, $sps);
    app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu); // segunda vez a TGU (todos saltarán pero igual cuenta)

    // Solo cuenta los exitosos (con clonados > 0). El segundo a TGU saltó todos.
    expect(ClonarItemsEntreZonas::totalClonadosHaciaZona($tgu->id))->toBe(1);
    expect(ClonarItemsEntreZonas::totalClonadosHaciaZona($sps->id))->toBe(1);
});

test('saltarDuplicados=false sí intenta clonar duplicados (advertencia: puede romper unique)', function (): void {
    // Como tenemos índice unique (zona_id, codigo) y el código se autogenera,
    // los duplicados por nombre+categoría NO chocan en DB porque tienen
    // códigos distintos (SRC-MAT-00001 origen → TGU-MAT-00002 nuevo).
    // Por eso saltarDuplicados=false es válido a nivel de schema, aunque
    // genere "duplicados lógicos".
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $unidad = UnidadMedida::factory()->create();

    Item::factory()->enZona($src)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::Materiales)
        ->create(['nombre' => 'Cemento']);

    Item::factory()->enZona($tgu)->conUnidad($unidad)
        ->deCategoria(CategoriaItem::Materiales)
        ->create(['nombre' => 'Cemento']);

    $resultado = app(ClonarItemsEntreZonas::class)->ejecutar($src, $tgu, saltarDuplicados: false);

    expect($resultado['clonados'])->toBe(1);
    expect($resultado['omitidos'])->toBe(0);
    expect($tgu->items()->count())->toBe(2);  // El preexistente + el clonado (duplicado lógico)
});
