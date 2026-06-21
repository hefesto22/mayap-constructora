<?php

declare(strict_types=1);

use App\Enums\TipoPago;
use App\Models\Empleado;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Tests de la capa de datos del catálogo de Empleados.
|--------------------------------------------------------------------------
*/

test('empleado persiste con casts correctos', function (): void {
    $empleado = Empleado::factory()->salario(6000)->create();

    expect($empleado->tipo_pago)->toBe(TipoPago::Salario)
        ->and($empleado->tarifa_base)->toBe('6000.00')
        ->and($empleado->activo)->toBeTrue();
});

test('auto-código EMP-##### se genera y es secuencial', function (): void {
    $a = Empleado::factory()->create();
    $b = Empleado::factory()->create();

    expect($a->codigo)->toBe('EMP-00001')
        ->and($b->codigo)->toBe('EMP-00002');
});

test('el mutator uppercase aplica a nombre, cargo y notas pero no a identidad', function (): void {
    $empleado = Empleado::factory()->create([
        'nombre'    => 'juan pérez',
        'cargo'     => 'maestro de obra',
        'notas'     => 'turno mañana',
        'identidad' => '0801-1990-12345',
    ]);

    expect($empleado->nombre)->toBe('JUAN PÉREZ')
        ->and($empleado->cargo)->toBe('MAESTRO DE OBRA')
        ->and($empleado->notas)->toBe('TURNO MAÑANA')
        ->and($empleado->identidad)->toBe('0801-1990-12345');
});

test('CHECK rechaza un tipo de pago fuera del enum', function (): void {
    DB::table('empleados')->insert([
        'codigo'    => 'EMP-99999',
        'nombre'    => 'X',
        'tipo_pago' => 'comision',
    ]);
})->throws(QueryException::class);

test('CHECK rechaza tarifa negativa', function (): void {
    DB::table('empleados')->insert([
        'codigo'      => 'EMP-99998',
        'nombre'      => 'X',
        'tarifa_base' => -1,
    ]);
})->throws(QueryException::class);

test('scope activos excluye los inactivos', function (): void {
    Empleado::factory()->count(3)->create();
    Empleado::factory()->inactivo()->create();

    expect(Empleado::query()->activos()->count())->toBe(3);
});
