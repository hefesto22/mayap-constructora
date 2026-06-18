<?php

declare(strict_types=1);

use App\Models\Ficha;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Defensa en profundidad: los CHECK constraints en DB protegen ante
| inserts directos por SQL, importaciones, otros lenguajes, etc.
| Todos estos tests usan DB::table()->insert() para evadir el modelo y
| comprobar que Postgres rechaza los datos inválidos.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->zona = Zona::factory()->create();
    $this->unidad = UnidadMedida::factory()->create();
    $this->ficha = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidad)->create();
});

test('CHECK rechaza tipo inválido (no item ni porcentaje)', function (): void {
    expect(fn () => DB::table('ficha_lineas')->insert([
        'ficha_id'   => $this->ficha->id,
        'tipo'       => 'invalido_xxx',
        'orden'      => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza tipo=item sin item_id', function (): void {
    expect(fn () => DB::table('ficha_lineas')->insert([
        'ficha_id'    => $this->ficha->id,
        'tipo'        => 'item',
        'orden'       => 0,
        'item_id'     => null,
        'rendimiento' => 1.0,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza tipo=item con campos de porcentaje pobladados', function (): void {
    expect(fn () => DB::table('ficha_lineas')->insert([
        'ficha_id'    => $this->ficha->id,
        'tipo'        => 'item',
        'orden'       => 0,
        'item_id'     => null,
        'rendimiento' => 1.0,
        'descripcion' => 'INDEBIDO',
        'porcentaje'  => 5.0,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza tipo=porcentaje sin descripcion', function (): void {
    expect(fn () => DB::table('ficha_lineas')->insert([
        'ficha_id'          => $this->ficha->id,
        'tipo'              => 'porcentaje',
        'orden'             => 0,
        'porcentaje'        => 5.0,
        'categoria_base'    => 'mano_obra',
        'categoria_destino' => 'herramienta_equipo',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza tipo=porcentaje con item_id pobladado', function (): void {
    $itemId = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create()->id;

    expect(fn () => DB::table('ficha_lineas')->insert([
        'ficha_id'          => $this->ficha->id,
        'tipo'              => 'porcentaje',
        'orden'             => 0,
        'item_id'           => $itemId,
        'descripcion'       => 'CONFLICTO',
        'porcentaje'        => 5.0,
        'categoria_base'    => 'mano_obra',
        'categoria_destino' => 'herramienta_equipo',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza rendimiento negativo', function (): void {
    $itemId = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create()->id;

    expect(fn () => DB::table('ficha_lineas')->insert([
        'ficha_id'    => $this->ficha->id,
        'tipo'        => 'item',
        'orden'       => 0,
        'item_id'     => $itemId,
        'rendimiento' => -0.5,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza desperdicio fuera de rango 0..100', function (): void {
    $itemId = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create()->id;

    expect(fn () => DB::table('ficha_lineas')->insert([
        'ficha_id'               => $this->ficha->id,
        'tipo'                   => 'item',
        'orden'                  => 0,
        'item_id'                => $itemId,
        'rendimiento'            => 1.0,
        'desperdicio_porcentaje' => 150.00,  // > 100
        'created_at'             => now(),
        'updated_at'             => now(),
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza categoria_base inválida', function (): void {
    expect(fn () => DB::table('ficha_lineas')->insert([
        'ficha_id'          => $this->ficha->id,
        'tipo'              => 'porcentaje',
        'orden'             => 0,
        'descripcion'       => 'TEST',
        'porcentaje'        => 5.0,
        'categoria_base'    => 'indirectos',  // NO permitido como base
        'categoria_destino' => 'herramienta_equipo',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]))->toThrow(QueryException::class);
});

test('CHECK acepta tipo=item válido completo', function (): void {
    $itemId = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create()->id;

    DB::table('ficha_lineas')->insert([
        'ficha_id'               => $this->ficha->id,
        'tipo'                   => 'item',
        'orden'                  => 0,
        'item_id'                => $itemId,
        'rendimiento'            => 0.892500,
        'desperdicio_porcentaje' => 5.0,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    expect(DB::table('ficha_lineas')->where('ficha_id', $this->ficha->id)->count())->toBe(1);
});

test('CHECK acepta tipo=porcentaje válido completo', function (): void {
    DB::table('ficha_lineas')->insert([
        'ficha_id'          => $this->ficha->id,
        'tipo'              => 'porcentaje',
        'orden'             => 0,
        'descripcion'       => 'HERRAMIENTA MENOR',
        'porcentaje'        => 5.0,
        'categoria_base'    => 'mano_obra',
        'categoria_destino' => 'herramienta_equipo',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    expect(DB::table('ficha_lineas')->where('ficha_id', $this->ficha->id)->count())->toBe(1);
});
