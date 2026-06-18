<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('cliente persiste con casts correctos', function (): void {
    $cliente = Cliente::factory()->create([
        'activo' => true,
    ]);

    $fresh = $cliente->fresh();

    expect($fresh)->not->toBeNull();
    expect($fresh->activo)->toBeTrue();
});

test('auto-código CLI-##### secuencial al crear', function (): void {
    $c1 = Cliente::factory()->create();
    $c2 = Cliente::factory()->create();
    $c3 = Cliente::factory()->create();

    expect($c1->codigo)->toBe('CLI-00001');
    expect($c2->codigo)->toBe('CLI-00002');
    expect($c3->codigo)->toBe('CLI-00003');
});

test('código manual provisto NO se sobreescribe', function (): void {
    $cliente = Cliente::factory()->create(['codigo' => 'CUSTOM-X']);

    expect($cliente->codigo)->toBe('CUSTOM-X');
});

test('soft delete NO recicla números — el siguiente sigue secuencia', function (): void {
    $c1 = Cliente::factory()->create();
    expect($c1->codigo)->toBe('CLI-00001');

    $c1->delete();

    $c2 = Cliente::factory()->create();
    expect($c2->codigo)->toBe('CLI-00002');
});

test('mutator uppercase aplica a nombre, ciudad, dirección', function (): void {
    $cliente = Cliente::factory()->create([
        'nombre'    => 'juan pérez s.a.',
        'ciudad'    => 'tegucigalpa',
        'direccion' => 'colonia palmira',
    ]);

    expect($cliente->fresh()->nombre)->toBe('JUAN PÉREZ S.A.');
    expect($cliente->fresh()->ciudad)->toBe('TEGUCIGALPA');
    expect($cliente->fresh()->direccion)->toBe('COLONIA PALMIRA');
});

test('RTN nulo se permite y se pueden crear múltiples sin RTN', function (): void {
    Cliente::factory()->sinRtn()->create();
    Cliente::factory()->sinRtn()->create();
    Cliente::factory()->sinRtn()->create();

    expect(Cliente::whereNull('rtn')->count())->toBe(3);
});

test('RTN duplicado se rechaza por unique parcial', function (): void {
    Cliente::factory()->create(['rtn' => '08019985012345']);

    expect(fn () => Cliente::factory()->create(['rtn' => '08019985012345']))
        ->toThrow(QueryException::class);
});

test('CHECK rechaza RTN con formato inválido (no 14 dígitos)', function (): void {
    // Insert crudo para evitar el path de Eloquent que en algunas
    // ramas no envuelve PDOException en QueryException — patrón
    // consistente con los otros tests de CHECK constraints.
    expect(fn () => DB::table('clientes')->insert([
        'codigo'     => 'CLI-TEST1',
        'nombre'     => 'TEST',
        'rtn'        => '12345',
        'activo'     => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('clientes')->insert([
        'codigo'     => 'CLI-TEST2',
        'nombre'     => 'TEST',
        'rtn'        => 'NO-NUMERICO-X',
        'activo'     => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza email con formato inválido', function (): void {
    expect(fn () => DB::table('clientes')->insert([
        'codigo'     => 'CLI-TEST3',
        'nombre'     => 'TEST',
        'email'      => 'no-es-email',
        'activo'     => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('scope activos filtra solo clientes con activo=true', function (): void {
    Cliente::factory()->count(3)->create();
    Cliente::factory()->inactivo()->count(2)->create();

    expect(Cliente::activos()->count())->toBe(3);
    expect(Cliente::count())->toBe(5);
});

test('scope buscar encuentra por nombre, RTN, email o teléfono', function (): void {
    Cliente::factory()->create([
        'nombre'   => 'COMERCIAL HONDUREÑA',
        'rtn'      => '08019985012345',
        'email'    => 'contacto@comercial.hn',
        'telefono' => '2552-3300',
    ]);

    Cliente::factory()->create([
        'nombre' => 'INVERSIONES MAYA',
        'rtn'    => '08011988054321',
    ]);

    expect(Cliente::buscar('COMERCIAL')->count())->toBe(1);
    expect(Cliente::buscar('08019985012345')->count())->toBe(1);
    expect(Cliente::buscar('comercial.hn')->count())->toBe(1);
    expect(Cliente::buscar('MAYA')->count())->toBe(1);
    expect(Cliente::buscar('INEXISTENTE')->count())->toBe(0);
});

test('relación proyectos retorna las cotizaciones del cliente', function (): void {
    $cliente = Cliente::factory()->create();
    $zona = Zona::factory()->create();

    Proyecto::factory()
        ->paraCliente($cliente)
        ->enZona($zona)
        ->count(3)
        ->create();

    expect($cliente->fresh()->proyectos)->toHaveCount(3);
});

test('etiqueta combina código, nombre y RTN cuando existe', function (): void {
    $conRtn = Cliente::factory()->create([
        'nombre' => 'TEST CLIENT',
        'rtn'    => '08019985012345',
    ]);

    $sinRtn = Cliente::factory()->sinRtn()->create([
        'nombre' => 'OTRO CLIENT',
    ]);

    expect($conRtn->etiqueta)->toContain('TEST CLIENT');
    expect($conRtn->etiqueta)->toContain('08019985012345');
    expect($conRtn->etiqueta)->toContain($conRtn->codigo);

    expect($sinRtn->etiqueta)->toContain('OTRO CLIENT');
    expect($sinRtn->etiqueta)->not->toContain('null');
});

test('FK restrict: cliente con proyectos NO se puede eliminar', function (): void {
    $cliente = Cliente::factory()->create();
    $zona = Zona::factory()->create();

    Proyecto::factory()->paraCliente($cliente)->enZona($zona)->create();

    expect(fn () => $cliente->forceDelete())
        ->toThrow(QueryException::class);
});
