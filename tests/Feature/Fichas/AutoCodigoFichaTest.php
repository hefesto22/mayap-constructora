<?php

declare(strict_types=1);

use App\Models\Ficha;
use App\Models\UnidadMedida;
use App\Models\Zona;

test('código de ficha se genera automáticamente al crear sin código', function (): void {
    $zona = Zona::factory()->create(['codigo' => 'SRC']);
    $unidad = UnidadMedida::factory()->create();

    $ficha = Ficha::create([
        'zona_id'             => $zona->id,
        'unidad_medida_id'    => $unidad->id,
        'nombre'              => 'Losa de concreto 10cm',
        'utilidad_porcentaje' => 25.00,
        'activa'              => true,
    ]);

    expect($ficha->codigo)->toBe('SRC-APU-00001');
});

test('códigos secuenciales por zona no chocan', function (): void {
    $zona = Zona::factory()->create(['codigo' => 'SRC']);
    $unidad = UnidadMedida::factory()->create();

    $datos = [
        'zona_id'             => $zona->id,
        'unidad_medida_id'    => $unidad->id,
        'utilidad_porcentaje' => 25.00,
        'activa'              => true,
    ];

    $f1 = Ficha::create([...$datos, 'nombre' => 'Ficha A']);
    $f2 = Ficha::create([...$datos, 'nombre' => 'Ficha B']);
    $f3 = Ficha::create([...$datos, 'nombre' => 'Ficha C']);

    expect($f1->codigo)->toBe('SRC-APU-00001');
    expect($f2->codigo)->toBe('SRC-APU-00002');
    expect($f3->codigo)->toBe('SRC-APU-00003');
});

test('códigos no chocan entre zonas distintas', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $unidad = UnidadMedida::factory()->create();

    $fSrc = Ficha::create([
        'zona_id'             => $src->id,
        'unidad_medida_id'    => $unidad->id,
        'nombre'              => 'Losa Santa Rosa',
        'utilidad_porcentaje' => 25.00,
        'activa'              => true,
    ]);

    $fTgu = Ficha::create([
        'zona_id'             => $tgu->id,
        'unidad_medida_id'    => $unidad->id,
        'nombre'              => 'Losa Tegucigalpa',
        'utilidad_porcentaje' => 25.00,
        'activa'              => true,
    ]);

    expect($fSrc->codigo)->toBe('SRC-APU-00001');
    expect($fTgu->codigo)->toBe('TGU-APU-00001');
});

test('código manualmente provisto se respeta', function (): void {
    $ficha = Ficha::factory()->create(['codigo' => 'CUSTOM-LOSA']);

    expect($ficha->codigo)->toBe('CUSTOM-LOSA');
});

test('numeración continúa después de eliminar fichas intermedias', function (): void {
    $zona = Zona::factory()->create(['codigo' => 'SRC']);
    $unidad = UnidadMedida::factory()->create();

    $datos = [
        'zona_id'             => $zona->id,
        'unidad_medida_id'    => $unidad->id,
        'utilidad_porcentaje' => 25.00,
        'activa'              => true,
    ];

    $f1 = Ficha::create([...$datos, 'nombre' => 'A']);
    $f2 = Ficha::create([...$datos, 'nombre' => 'B']);
    $f3 = Ficha::create([...$datos, 'nombre' => 'C']);

    expect($f3->codigo)->toBe('SRC-APU-00003');

    $f2->delete();

    $f4 = Ficha::create([...$datos, 'nombre' => 'D']);
    expect($f4->codigo)->toBe('SRC-APU-00004');
});
