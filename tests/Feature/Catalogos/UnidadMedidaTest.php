<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\UnidadMedida;
use Database\Seeders\UnidadMedidaSeeder;
use Illuminate\Database\QueryException;

test('seeder de unidades carga las 17 unidades base sin duplicar al re-ejecutar', function (): void {
    $this->seed(UnidadMedidaSeeder::class);
    $primerConteo = UnidadMedida::count();

    expect($primerConteo)->toBe(17);

    // Re-ejecutar es idempotente
    $this->seed(UnidadMedidaSeeder::class);
    expect(UnidadMedida::count())->toBe($primerConteo);
});

test('código de unidad es único a nivel global', function (): void {
    UnidadMedida::factory()->create(['codigo' => 'M2']);

    expect(static fn () => UnidadMedida::factory()->create(['codigo' => 'M2']))
        ->toThrow(QueryException::class);
});

test('scope activas filtra correctamente', function (): void {
    UnidadMedida::factory()->count(3)->create(['activo' => true]);
    UnidadMedida::factory()->count(2)->create(['activo' => false]);

    expect(UnidadMedida::activas()->count())->toBe(3);
    expect(UnidadMedida::count())->toBe(5);
});

test('atributo etiqueta combina código y nombre (ambos uppercase)', function (): void {
    $u = UnidadMedida::factory()->create([
        'codigo' => 'BOLSA',
        'nombre' => 'Bolsa',
    ]);

    // El mutator transforma 'Bolsa' → 'BOLSA' al persistir.
    expect($u->etiqueta)->toBe('BOLSA — BOLSA');
});

test('eliminar unidad falla si tiene items asociados (FK restrict)', function (): void {
    $unidad = UnidadMedida::factory()->create();
    Item::factory()->conUnidad($unidad)->create();

    expect(static fn () => $unidad->delete())->toThrow(QueryException::class);
});
