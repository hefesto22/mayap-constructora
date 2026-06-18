<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Models\UnidadMedida;
use App\Models\Zona;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->unidad = UnidadMedida::factory()->create();
    $this->proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();
    $this->ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create();
});

/**
 * Helper para tests de CHECK constraints — insert crudo que evita los
 * casts de Eloquent y reta directamente al constraint Postgres.
 *
 * @param array<string, mixed> $overrides
 */
function insertarRenglonCrudo(int $proyectoId, int $fichaId, array $overrides = []): bool
{
    return DB::table('proyecto_renglones')->insert(array_merge([
        'proyecto_id'              => $proyectoId,
        'ficha_id'                 => $fichaId,
        'orden'                    => 0,
        'capitulo'                 => null,
        'cantidad'                 => 1.0000,
        'precio_unitario_snapshot' => 100.00,
        'subtotal_cache'           => 100.00,
        'notas'                    => null,
        'created_at'               => now(),
        'updated_at'               => now(),
    ], $overrides));
}

test('renglón persiste con casts correctos', function (): void {
    $renglon = ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('120.5000', '2604.37')
        ->create();

    $fresh = $renglon->fresh();

    expect($fresh)->not->toBeNull();
    expect($fresh->cantidad)->toBe('120.5000');
    expect($fresh->precio_unitario_snapshot)->toBe('2604.37');
    // 120.5 × 2604.37 = 313826.585 → redondea a 313826.59
    expect($fresh->subtotal_cache)->toBe('313826.59');
});

test('relaciones proyecto y ficha funcionan', function (): void {
    $renglon = ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->create();

    expect($renglon->proyecto->id)->toBe($this->proyecto->id);
    expect($renglon->ficha->id)->toBe($this->ficha->id);
});

test('cascade delete: eliminar proyecto elimina sus renglones', function (): void {
    ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->count(3)
        ->create();

    expect(ProyectoRenglon::where('proyecto_id', $this->proyecto->id)->count())->toBe(3);

    $this->proyecto->forceDelete();

    expect(ProyectoRenglon::where('proyecto_id', $this->proyecto->id)->count())->toBe(0);
});

test('FK restrict: ficha con renglones NO se puede eliminar', function (): void {
    ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->create();

    expect(fn () => $this->ficha->forceDelete())
        ->toThrow(QueryException::class);
});

test('CHECK rechaza cantidad cero o negativa', function (): void {
    expect(fn () => insertarRenglonCrudo($this->proyecto->id, $this->ficha->id, [
        'cantidad' => 0.0000, 'precio_unitario_snapshot' => 100.00, 'subtotal_cache' => 0.00,
    ]))->toThrow(QueryException::class);

    expect(fn () => insertarRenglonCrudo($this->proyecto->id, $this->ficha->id, [
        'cantidad' => -5.0000, 'precio_unitario_snapshot' => 100.00, 'subtotal_cache' => -500.00,
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza precio_unitario_snapshot negativo', function (): void {
    expect(fn () => insertarRenglonCrudo($this->proyecto->id, $this->ficha->id, [
        'cantidad' => 1.0000, 'precio_unitario_snapshot' => -100.00, 'subtotal_cache' => -100.00,
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza subtotal_cache incoherente con cantidad × precio', function (): void {
    // Subtotal manualmente forzado a un valor que NO corresponde a cantidad × precio.
    expect(fn () => insertarRenglonCrudo($this->proyecto->id, $this->ficha->id, [
        'cantidad'                 => 10.0000,
        'precio_unitario_snapshot' => 100.00,
        'subtotal_cache'           => 500.00,  // debería ser 1000.00
    ]))->toThrow(QueryException::class);
});

test('subtotal_cache acepta margen de redondeo de 0.02', function (): void {
    // 33.33 × 3 = 99.99, pero podríamos tener 99.98 o 100.00 por redondeo.
    $renglon = ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->create([
            'cantidad'                 => '3.0000',
            'precio_unitario_snapshot' => '33.33',
            'subtotal_cache'           => '99.99',
        ]);

    expect($renglon)->not->toBeNull();
});

test('mutator uppercase aplica a capítulo y notas', function (): void {
    $renglon = ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->create([
            'capitulo' => '01 preliminares',
            'notas'    => 'incluir trazado y desalojo',
        ]);

    $fresh = $renglon->fresh();

    expect($fresh->capitulo)->toBe('01 PRELIMINARES');
    expect($fresh->notas)->toBe('INCLUIR TRAZADO Y DESALOJO');
});

test('scope delProyecto filtra por proyecto_id', function (): void {
    $otroProyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->count(3)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($otroProyecto)
        ->conFicha($this->ficha)
        ->count(2)
        ->create();

    expect(ProyectoRenglon::delProyecto($this->proyecto->id)->count())->toBe(3);
    expect(ProyectoRenglon::delProyecto($otroProyecto->id)->count())->toBe(2);
});

test('scope delCapitulo filtra por capítulo string', function (): void {
    ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->enCapitulo('01 PRELIMINARES')
        ->count(2)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->enCapitulo('02 CIMENTACIÓN')
        ->count(3)
        ->create();

    expect(ProyectoRenglon::delCapitulo('01 PRELIMINARES')->count())->toBe(2);
    expect(ProyectoRenglon::delCapitulo('02 CIMENTACIÓN')->count())->toBe(3);
});

test('relación renglones desde el proyecto retorna ordenados por orden', function (): void {
    ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->create(['orden' => 5]);

    ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->create(['orden' => 1]);

    ProyectoRenglon::factory()
        ->paraProyecto($this->proyecto)
        ->conFicha($this->ficha)
        ->create(['orden' => 3]);

    $renglones = $this->proyecto->fresh()->renglones;

    expect($renglones->pluck('orden')->all())->toBe([1, 3, 5]);
});
