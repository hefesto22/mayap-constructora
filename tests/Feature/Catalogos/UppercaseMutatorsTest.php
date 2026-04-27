<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;

// ─── Item ─────────────────────────────────────────────────────────

test('Item: nombre se uppercase al guardar', function (): void {
    $item = Item::factory()->create(['nombre' => 'cemento gris argos']);

    expect($item->nombre)->toBe('CEMENTO GRIS ARGOS');
});

test('Item: descripcion se uppercase al guardar (con acentos UTF-8)', function (): void {
    $item = Item::factory()->create([
        'descripcion' => 'descripción con acentos ñ y eñe',
    ]);

    expect($item->descripcion)->toBe('DESCRIPCIÓN CON ACENTOS Ñ Y EÑE');
});

test('Item: observaciones_precio se uppercase', function (): void {
    $item = Item::factory()->create([
        'observaciones_precio' => 'incluye flete a obra',
    ]);

    expect($item->observaciones_precio)->toBe('INCLUYE FLETE A OBRA');
});

test('Item: codigo manual se uppercase', function (): void {
    $item = Item::factory()->create(['codigo' => 'custom-abc-001']);

    expect($item->codigo)->toBe('CUSTOM-ABC-001');
});

test('Item: campos null se mantienen null (no se transforman a string vacío)', function (): void {
    $item = Item::factory()->create([
        'descripcion'          => null,
        'observaciones_precio' => null,
    ]);

    expect($item->descripcion)->toBeNull();
    expect($item->observaciones_precio)->toBeNull();
});

test('Item: strings vacíos o solo espacios se normalizan a null', function (): void {
    $item = Item::factory()->create([
        'descripcion'          => '   ',
        'observaciones_precio' => '',
    ]);

    expect($item->descripcion)->toBeNull();
    expect($item->observaciones_precio)->toBeNull();
});

// ─── Zona ─────────────────────────────────────────────────────────

test('Zona: codigo, nombre y descripcion se uppercase', function (): void {
    $zona = Zona::factory()->create([
        'codigo'      => 'tgu',
        'nombre'      => 'tegucigalpa',
        'descripcion' => 'capital del país',
    ]);

    expect($zona->codigo)->toBe('TGU');
    expect($zona->nombre)->toBe('TEGUCIGALPA');
    expect($zona->descripcion)->toBe('CAPITAL DEL PAÍS');
});

// ─── UnidadMedida ────────────────────────────────────────────────

test('UnidadMedida: codigo y nombre se uppercase', function (): void {
    $u = UnidadMedida::factory()->create([
        'codigo' => 'm3',
        'nombre' => 'metro cúbico',
    ]);

    expect($u->codigo)->toBe('M3');
    expect($u->nombre)->toBe('METRO CÚBICO');
});

test('UnidadMedida: simbolo NO se uppercase (m² ≠ M²)', function (): void {
    $u = UnidadMedida::factory()->create([
        'codigo'  => 'M2',
        'nombre'  => 'Metro cuadrado',
        'simbolo' => 'm²',
    ]);

    expect($u->simbolo)->toBe('m²');
});
