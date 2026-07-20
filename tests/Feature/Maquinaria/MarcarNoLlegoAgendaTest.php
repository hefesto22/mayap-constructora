<?php

declare(strict_types=1);

use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Maquinaria\MarcarNoLlegoAgendaService;
use App\Support\Roles;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Contingencia "NO llegó" (decisión Mauricio 2026-07-20).
|--------------------------------------------------------------------------
| Una agenda vencida sin llegada confirmada queda ROJA en el calendario.
| Si la máquina nunca fue, se marca "no llegó" con motivo: queda quién,
| cuándo y por qué (bitácora de la obra + campanita a maquinaria) y el
| evento se retira. Ni el futuro, ni lo confirmado, ni dos veces.
*/

beforeEach(function (): void {
    $this->servicio = app(MarcarNoLlegoAgendaService::class);
});

function agendaVencida(?Proyecto $obra = null): AgendaMaquina
{
    return AgendaMaquina::factory()->create([
        'maquina_id'   => Maquina::factory()->create(['nombre' => 'VOLQUETA MACK 12M3'])->id,
        'proyecto_id'  => ($obra ?? Proyecto::factory()->enEjecucion()->create())->id,
        'fecha'        => today()->subDays(2)->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);
}

test('GOLDEN: el encargado marca "no llegó" → queda quién/cuándo/motivo, bitácora en la obra y campanita a maquinaria', function (): void {
    Role::firstOrCreate(['name' => Roles::MAQUINARIA, 'guard_name' => 'web']);
    $jefe = User::factory()->create();
    $jefe->assignRole(Roles::MAQUINARIA);

    $encargado = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA NORTE']);
    $obra->encargados()->attach($encargado);

    $agendado = agendaVencida($obra);

    $marcado = $this->servicio->marcar($agendado, 'se daño en ruta', $encargado);

    expect($marcado->no_llego_at)->not->toBeNull()
        ->and($marcado->no_llego_por)->toBe($encargado->id)
        ->and($marcado->no_llego_motivo)->toBe('SE DAÑO EN RUTA') // mayúsculas de la casa
        ->and($jefe->notifications()->count())->toBe(1)
        ->and(json_encode($jefe->notifications()->first()->data))->toContain('VOLQUETA MACK 12M3')
        ->toContain('NO lleg'); // json_encode escapa la o con tilde (\u00f3)

    // La constancia queda en la bitácora de la obra.
    $registro = Activity::query()
        ->where('event', 'maquina_no_llego')
        ->latest('id')
        ->first();

    expect($registro)->not->toBeNull()
        ->and($registro->subject_id)->toBe($obra->id)
        ->and($registro->description)->toContain('SE DAÑO EN RUTA');
});

test('sin motivo no hay constancia: el motivo es obligatorio', function (): void {
    Role::firstOrCreate(['name' => Roles::GERENCIA, 'guard_name' => 'web']);
    $gerente = User::factory()->create();
    $gerente->assignRole(Roles::GERENCIA);

    $this->servicio->marcar(agendaVencida(), '   ', $gerente);
})->throws(AgendaInvalidaException::class, 'motivo');

test('el futuro no se marca: hoy la máquina todavía puede llegar', function (): void {
    Role::firstOrCreate(['name' => Roles::GERENCIA, 'guard_name' => 'web']);
    $gerente = User::factory()->create();
    $gerente->assignRole(Roles::GERENCIA);

    $deHoy = AgendaMaquina::factory()->create([
        'fecha' => today()->toDateString(),
    ]);

    $this->servicio->marcar($deHoy, 'NO VA A LLEGAR', $gerente);
})->throws(AgendaInvalidaException::class, 'todavía puede llegar');

test('si la llegada SÍ fue confirmada, no se puede decir que no llegó', function (): void {
    Role::firstOrCreate(['name' => Roles::GERENCIA, 'guard_name' => 'web']);
    $gerente = User::factory()->create();
    $gerente->assignRole(Roles::GERENCIA);

    $agendado = agendaVencida();
    $agendado->forceFill(['llegada_confirmada_at' => now()->subDays(2)])->save();

    $this->servicio->marcar($agendado, 'NO LLEGO', $gerente);
})->throws(AgendaInvalidaException::class, 'SÍ fue confirmada');

test('la constancia es un hecho, no un toggle: no se marca dos veces', function (): void {
    Role::firstOrCreate(['name' => Roles::GERENCIA, 'guard_name' => 'web']);
    $gerente = User::factory()->create();
    $gerente->assignRole(Roles::GERENCIA);

    $agendado = agendaVencida();
    $this->servicio->marcar($agendado, 'SE DAÑO EN RUTA', $gerente);

    $this->servicio->marcar($agendado->refresh(), 'OTRO MOTIVO', $gerente);
})->throws(AgendaInvalidaException::class, 'dos veces');

test('solo el encargado de ESA obra (o maquinaria/gerencia) deja la constancia', function (): void {
    $ajeno = User::factory()->create(); // sin rol y sin obra

    $this->servicio->marcar(agendaVencida(), 'NO LLEGO', $ajeno);
})->throws(AgendaInvalidaException::class, 'encargado');
