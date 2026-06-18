<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
});

/**
 * Helper para tests de CHECK constraints — insert crudo con DB::table()
 * que evita el cast de enum (que lanza ValueError ANTES de tocar DB) y
 * deja que el constraint Postgres reaccione directamente.
 *
 * @param array<string, mixed> $overrides
 */
function insertarProyectoCrudo(int $zonaId, int $clienteId, array $overrides = []): bool
{
    return DB::table('proyectos')->insert(array_merge([
        'codigo'         => 'PROY-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'zona_id'        => $zonaId,
        'cliente_id'     => $clienteId,
        'nombre'         => 'TEST PROYECTO',
        'direccion_obra' => 'TEST DIRECCION',
        'fecha_emision'  => '2026-05-01',
        'fecha_validez'  => '2026-06-01',
        'estado'         => 'borrador',
        'moneda'         => 'HNL',
        'aplica_isv'     => true,
        'isv_porcentaje' => 15.00,
        'subtotal_cache' => 0,
        'isv_cache'      => 0,
        'total_cache'    => 0,
        'created_at'     => now(),
        'updated_at'     => now(),
    ], $overrides));
}

test('proyecto persiste con casts correctos', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create([
            'isv_porcentaje' => 15.50,
            'aplica_isv'     => true,
        ]);

    $fresh = $proyecto->fresh();

    expect($fresh)->not->toBeNull();
    expect($fresh->estado)->toBe(EstadoProyecto::Borrador);
    expect($fresh->aplica_isv)->toBeTrue();
    expect($fresh->isv_porcentaje)->toBe('15.50');
    expect($fresh->moneda)->toBe('HNL');
});

test('auto-código PROY-YYYY-##### se genera con el año de fecha_emision', function (): void {
    $p1 = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['fecha_emision' => '2026-03-15', 'fecha_validez' => '2026-04-15']);

    $p2 = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['fecha_emision' => '2026-08-22', 'fecha_validez' => '2026-09-22']);

    expect($p1->codigo)->toBe('PROY-2026-00001');
    expect($p2->codigo)->toBe('PROY-2026-00002');
});

test('contador de proyectos se reinicia por año', function (): void {
    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['fecha_emision' => '2026-12-30', 'fecha_validez' => '2027-01-30']);

    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['fecha_emision' => '2026-12-31', 'fecha_validez' => '2027-01-31']);

    $primer2027 = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['fecha_emision' => '2027-01-02', 'fecha_validez' => '2027-02-02']);

    expect($primer2027->codigo)->toBe('PROY-2027-00001');
});

test('mutator uppercase aplica a nombre, descripción, dirección, notas', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create([
            'nombre'         => 'casa de habitación',
            'descripcion'    => 'dos niveles con tres dormitorios',
            'direccion_obra' => 'colonia las mercedes',
            'notas'          => 'cliente apurado',
        ]);

    $fresh = $proyecto->fresh();

    expect($fresh->nombre)->toBe('CASA DE HABITACIÓN');
    expect($fresh->descripcion)->toBe('DOS NIVELES CON TRES DORMITORIOS');
    expect($fresh->direccion_obra)->toBe('COLONIA LAS MERCEDES');
    expect($fresh->notas)->toBe('CLIENTE APURADO');
});

test('CHECK rechaza estado inválido (no en el enum)', function (): void {
    expect(fn () => insertarProyectoCrudo($this->zona->id, $this->cliente->id, [
        'estado' => 'aprobado_pero_con_dudas',
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza ISV fuera de rango 0-100', function (): void {
    expect(fn () => insertarProyectoCrudo($this->zona->id, $this->cliente->id, [
        'isv_porcentaje' => 150.00,
    ]))->toThrow(QueryException::class);

    expect(fn () => insertarProyectoCrudo($this->zona->id, $this->cliente->id, [
        'isv_porcentaje' => -5.00,
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza fecha_validez anterior a fecha_emision', function (): void {
    expect(fn () => insertarProyectoCrudo($this->zona->id, $this->cliente->id, [
        'fecha_emision' => '2026-05-15',
        'fecha_validez' => '2026-05-10',  // ANTES
    ]))->toThrow(QueryException::class);
});

test('CHECK rechaza aplica_isv=false con isv_porcentaje > 0', function (): void {
    expect(fn () => insertarProyectoCrudo($this->zona->id, $this->cliente->id, [
        'aplica_isv'     => false,
        'isv_porcentaje' => 15.00,  // INCONSISTENTE
    ]))->toThrow(QueryException::class);
});

test('proyecto exento (aplica_isv=false) acepta isv_porcentaje=0', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->exento()
        ->create();

    expect($proyecto->aplica_isv)->toBeFalse();
    expect($proyecto->isv_porcentaje)->toBe('0.00');
});

test('CHECK rechaza totales negativos en cache', function (): void {
    expect(fn () => insertarProyectoCrudo($this->zona->id, $this->cliente->id, [
        'subtotal_cache' => -100,
    ]))->toThrow(QueryException::class);
});

test('scope deZona filtra por zona', function (): void {
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);

    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->count(2)->create();
    Proyecto::factory()->enZona($tgu)->paraCliente($this->cliente)->count(3)->create();

    expect(Proyecto::deZona($this->zona->id)->count())->toBe(2);
    expect(Proyecto::deZona($tgu->id)->count())->toBe(3);
});

test('scope conEstado filtra por estado del enum', function (): void {
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->count(2)->create();  // borrador
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->enviada()->count(3)->create();
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->aprobada()->count(1)->create();

    expect(Proyecto::conEstado(EstadoProyecto::Borrador)->count())->toBe(2);
    expect(Proyecto::conEstado(EstadoProyecto::Enviada)->count())->toBe(3);
    expect(Proyecto::conEstado(EstadoProyecto::Aprobada)->count())->toBe(1);
});

test('scope enviadosVencidos detecta proyectos enviados con fecha pasada', function (): void {
    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->conFechasVencidas()
        ->count(2)
        ->create();

    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->create();  // fecha futura

    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->aprobada()
        ->conFechasVencidas()
        ->create();  // vencida pero aprobada — NO entra

    expect(Proyecto::enviadosVencidos()->count())->toBe(2);
});

test('estaVencida() detecta enviada con fecha pasada', function (): void {
    $vencida = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->conFechasVencidas()
        ->create();

    $vigente = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->create();

    expect($vencida->estaVencida())->toBeTrue();
    expect($vigente->estaVencida())->toBeFalse();
});

test('FK restrict: zona con proyectos NO se puede eliminar', function (): void {
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->create();

    expect(fn () => $this->zona->forceDelete())
        ->toThrow(QueryException::class);
});

test('soft delete preserva el código (no se reasigna a otro proyecto)', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['fecha_emision' => '2026-05-01', 'fecha_validez' => '2026-06-01']);

    expect($proyecto->codigo)->toBe('PROY-2026-00001');

    $proyecto->delete();

    $nuevo = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['fecha_emision' => '2026-05-02', 'fecha_validez' => '2026-06-02']);

    expect($nuevo->codigo)->toBe('PROY-2026-00002');
});
