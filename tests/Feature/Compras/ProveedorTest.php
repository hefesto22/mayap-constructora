<?php

declare(strict_types=1);

use App\Enums\CondicionPago;
use App\Models\Proveedor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Tests del modelo Proveedor (catálogo, Fase B).
|--------------------------------------------------------------------------
*/

test('proveedor persiste con casts correctos', function (): void {
    $proveedor = Proveedor::factory()->aCredito(45)->create();

    expect($proveedor->condicion_pago)->toBe(CondicionPago::Credito)
        ->and($proveedor->dias_credito)->toBe(45)
        ->and($proveedor->activo)->toBeTrue();
});

test('auto-código PRV-##### secuencial al crear', function (): void {
    $primero = Proveedor::factory()->create();
    $segundo = Proveedor::factory()->create();

    expect($primero->codigo)->toBe('PRV-00001')
        ->and($segundo->codigo)->toBe('PRV-00002');
});

test('mutator uppercase aplica a nombre, ciudad y dirección', function (): void {
    $proveedor = Proveedor::factory()->create([
        'nombre'    => 'ferretería el constructor',
        'ciudad'    => 'san pedro sula',
        'direccion' => 'barrio el centro',
    ]);

    expect($proveedor->nombre)->toBe('FERRETERÍA EL CONSTRUCTOR')
        ->and($proveedor->ciudad)->toBe('SAN PEDRO SULA')
        ->and($proveedor->direccion)->toBe('BARRIO EL CENTRO');
});

test('RTN nulo se permite y se pueden crear múltiples sin RTN', function (): void {
    Proveedor::factory()->count(3)->create(['rtn' => null]);

    expect(Proveedor::query()->count())->toBe(3);
});

test('RTN duplicado se rechaza por unique parcial', function (): void {
    Proveedor::factory()->create(['rtn' => '08019985012345']);
    Proveedor::factory()->create(['rtn' => '08019985012345']);
})->throws(QueryException::class);

test('CHECK rechaza RTN con formato inválido (no 14 dígitos)', function (): void {
    Proveedor::factory()->create(['rtn' => '123']);
})->throws(QueryException::class);

test('CHECK rechaza condición de pago inválida a nivel DB (insert crudo)', function (): void {
    // Insert directo para evitar el cast del enum y exercitar el CHECK de Postgres.
    DB::table('proveedores')->insert([
        'codigo'         => 'PRV-09999',
        'nombre'         => 'X',
        'condicion_pago' => 'cheque',
        'dias_credito'   => 0,
        'activo'         => true,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
})->throws(QueryException::class);

test('scope activos filtra solo proveedores activos', function (): void {
    Proveedor::factory()->count(2)->create();
    Proveedor::factory()->inactivo()->create();

    expect(Proveedor::query()->activos()->count())->toBe(2);
});
