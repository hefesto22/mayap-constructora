<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\Zona;
use Database\Seeders\ZonaSeeder;
use Illuminate\Database\QueryException;

test('seeder crea Santa Rosa de Copán como zona principal (nombre uppercased)', function (): void {
    $this->seed(ZonaSeeder::class);

    $src = Zona::where('codigo', 'SRC')->first();

    expect($src)->not->toBeNull();
    // El mutator uppercase del modelo transforma el nombre del seeder.
    expect($src->nombre)->toBe('SANTA ROSA DE COPÁN');
    expect($src->activa)->toBeTrue();
});

test('seeder es idempotente: re-ejecutar no crea duplicados', function (): void {
    $this->seed(ZonaSeeder::class);
    $this->seed(ZonaSeeder::class);
    $this->seed(ZonaSeeder::class);

    expect(Zona::where('codigo', 'SRC')->count())->toBe(1);
});

test('código de zona es único', function (): void {
    Zona::factory()->create(['codigo' => 'TGU']);

    expect(static fn () => Zona::factory()->create(['codigo' => 'TGU']))
        ->toThrow(QueryException::class);
});

test('scope activas filtra zonas inactivas', function (): void {
    Zona::factory()->count(2)->create(['activa' => true]);
    Zona::factory()->count(1)->create(['activa' => false]);

    expect(Zona::activas()->count())->toBe(2);
});

test('eliminar zona falla si tiene items asociados', function (): void {
    $zona = Zona::factory()->create();
    Item::factory()->enZona($zona)->create();

    expect(static fn () => $zona->delete())->toThrow(QueryException::class);
});
