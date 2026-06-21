<?php

declare(strict_types=1);

use App\Enums\EstadoMaquina;
use App\Enums\TipoMaquina;
use App\Models\Maquina;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Tests de la capa de datos del catálogo de Maquinaria.
|--------------------------------------------------------------------------
*/

test('máquina persiste con casts correctos', function (): void {
    $maquina = Maquina::factory()->create([
        'tipo'             => TipoMaquina::Excavadora->value,
        'horometro_actual' => 1200.50,
        'tarifa_hora'      => 1500,
        'jornada_horas'    => 8,
        'estado'           => EstadoMaquina::Disponible->value,
    ]);

    expect($maquina->tipo)->toBe(TipoMaquina::Excavadora)
        ->and($maquina->estado)->toBe(EstadoMaquina::Disponible)
        ->and($maquina->horometro_actual)->toBe('1200.50')
        ->and($maquina->tarifa_hora)->toBe('1500.00')
        ->and($maquina->activo)->toBeTrue();
});

test('auto-código MAQ-##### se genera y es secuencial', function (): void {
    $a = Maquina::factory()->create();
    $b = Maquina::factory()->create();

    expect($a->codigo)->toBe('MAQ-00001')
        ->and($b->codigo)->toBe('MAQ-00002');
});

test('el mutator uppercase aplica a nombre, marca, modelo, serie y notas', function (): void {
    $maquina = Maquina::factory()->create([
        'nombre' => 'excavadora cat 320',
        'marca'  => 'caterpillar',
        'modelo' => '320d',
        'serie'  => 'sn-123',
        'notas'  => 'opera juan',
    ]);

    expect($maquina->nombre)->toBe('EXCAVADORA CAT 320')
        ->and($maquina->marca)->toBe('CATERPILLAR')
        ->and($maquina->modelo)->toBe('320D')
        ->and($maquina->serie)->toBe('SN-123')
        ->and($maquina->notas)->toBe('OPERA JUAN');
});

test('CHECK rechaza un tipo fuera del enum', function (): void {
    DB::table('maquinas')->insert([
        'codigo' => 'MAQ-99999',
        'nombre' => 'X',
        'tipo'   => 'tractor_lunar',
    ]);
})->throws(QueryException::class);

test('CHECK rechaza un estado fuera del enum', function (): void {
    DB::table('maquinas')->insert([
        'codigo' => 'MAQ-99998',
        'nombre' => 'X',
        'estado' => 'volando',
    ]);
})->throws(QueryException::class);

test('CHECK rechaza horómetro negativo', function (): void {
    DB::table('maquinas')->insert([
        'codigo'           => 'MAQ-99997',
        'nombre'           => 'X',
        'horometro_actual' => -1,
    ]);
})->throws(QueryException::class);

test('CHECK rechaza tarifa negativa', function (): void {
    DB::table('maquinas')->insert([
        'codigo'      => 'MAQ-99996',
        'nombre'      => 'X',
        'tarifa_hora' => -5,
    ]);
})->throws(QueryException::class);

test('CHECK rechaza jornada cero o negativa', function (): void {
    DB::table('maquinas')->insert([
        'codigo'        => 'MAQ-99995',
        'nombre'        => 'X',
        'jornada_horas' => 0,
    ]);
})->throws(QueryException::class);

test('scope disponibles solo trae máquinas libres', function (): void {
    Maquina::factory()->count(2)->create();
    Maquina::factory()->asignada()->create();
    Maquina::factory()->enMantenimiento()->create();

    expect(Maquina::query()->disponibles()->count())->toBe(2);
});

test('scope activas excluye las inactivas', function (): void {
    Maquina::factory()->count(3)->create();
    Maquina::factory()->inactiva()->create();

    expect(Maquina::query()->activas()->count())->toBe(3);
});

test('la máquina de baja es estado terminal y disponible permite asignarse', function (): void {
    expect(EstadoMaquina::Baja->esTerminal())->toBeTrue()
        ->and(EstadoMaquina::Disponible->esTerminal())->toBeFalse()
        ->and(EstadoMaquina::Disponible->puedeAsignarse())->toBeTrue()
        ->and(EstadoMaquina::Asignada->puedeAsignarse())->toBeFalse()
        ->and(EstadoMaquina::Disponible->puedeTransicionarA(EstadoMaquina::Asignada))->toBeTrue()
        ->and(EstadoMaquina::Baja->puedeTransicionarA(EstadoMaquina::Disponible))->toBeFalse();
});
