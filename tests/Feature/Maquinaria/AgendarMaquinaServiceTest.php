<?php

declare(strict_types=1);

use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Maquinaria\AgendarMaquinaService;

/*
|--------------------------------------------------------------------------
| Agendar máquina — compromiso simple: "llega a las X a la obra Y el día Z".
|--------------------------------------------------------------------------
| Única puerta de creación: valida fecha, obra viva, máquina no dada de
| baja, choque con mantenimiento y duplicados. Sin horas estimadas — las
| horas reales las escribe la jornada (decisión Mauricio 2026-07-14).
*/

beforeEach(function (): void {
    $this->servicio = app(AgendarMaquinaService::class);
});

test('GOLDEN: agenda una máquina a una obra viva en fecha futura con hora de llegada', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obra = Proyecto::factory()->enEjecucion()->create();

    $agendado = $this->servicio->agendar(
        maquinaId: $maquina->id,
        proyectoId: $obra->id,
        fecha: today()->addDays(5)->toDateString(),
        notas: 'MEDIO DIA EN ZANJEO',
        userId: null,
        horaEntrada: '07:00:00',
    );

    expect($agendado->exists)->toBeTrue()
        ->and($agendado->hora_entrada)->toBe('07:00:00')
        ->and($agendado->horaEntradaCorta())->toBe('07:00')
        ->and($agendado->fecha->toDateString())->toBe(today()->addDays(5)->toDateString());
});

test('no se agenda en el pasado', function (): void {
    $maquina = Maquina::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();

    expect(fn () => $this->servicio->agendar($maquina->id, $obra->id, today()->subDay()->toDateString()))
        ->toThrow(AgendaInvalidaException::class, 'pasado');
});

test('máquina en mantenimiento ese día NO se agenda (rango y abierto)', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'RETRO JD']);
    $obra = Proyecto::factory()->enEjecucion()->create();

    // Mantenimiento ABIERTO desde mañana: bloquea cualquier fecha posterior.
    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'fecha_inicio' => today()->addDay()->toDateString(),
        'fecha_fin'    => null,
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    expect(fn () => $this->servicio->agendar($maquina->id, $obra->id, today()->addDays(10)->toDateString()))
        ->toThrow(AgendaInvalidaException::class, 'mantenimiento');

    // Hoy (antes del mantenimiento) sí se puede.
    $agendado = $this->servicio->agendar($maquina->id, $obra->id, today()->toDateString());
    expect($agendado->exists)->toBeTrue();
});

test('una máquina comprometida no se agenda a NINGUNA otra obra hasta que salga', function (): void {
    $maquina = Maquina::factory()->create();
    $obraA = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA ALFA']);
    $obraB = Proyecto::factory()->enEjecucion()->create();
    $fecha = today()->addDays(3)->toDateString();

    $this->servicio->agendar($maquina->id, $obraA->id, $fecha, horaEntrada: '08:00:00');

    // Ni a la misma obra otra vez...
    expect(fn () => $this->servicio->agendar($maquina->id, $obraA->id, $fecha, horaEntrada: '14:00:00'))
        ->toThrow(AgendaInvalidaException::class, 'OBRA ALFA');

    // ...ni a otra el mismo día, ni días después. Desde 2026-09-04 la
    // estadía no tiene fecha de fin: la máquina sigue en OBRA ALFA hasta
    // que el encargado registre la salida, y el sistema no puede adivinar
    // que ya se desocupó. La vía para liberarla es marcar que terminó.
    expect(fn () => $this->servicio->agendar($maquina->id, $obraB->id, $fecha, horaEntrada: '13:00:00'))
        ->toThrow(AgendaInvalidaException::class, 'OBRA ALFA');

    expect(fn () => $this->servicio->agendar($maquina->id, $obraB->id, today()->addDays(9)->toDateString()))
        ->toThrow(AgendaInvalidaException::class, 'OBRA ALFA');
});

test('LOTE: varias máquinas al mismo día; lo que choca se salta sin abortar el resto', function (): void {
    $excavadora = Maquina::factory()->create(['nombre' => 'EXCAVADORA']);
    $vibro = Maquina::factory()->create(['nombre' => 'VIBRO']);
    $obra = Proyecto::factory()->enEjecucion()->create();

    $dia = today()->addWeek()->startOfWeek();

    // La vibro está en el taller ese día (reparación abierta).
    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $vibro->id,
        'fecha_inicio' => $dia->copy()->subDay()->toDateString(),
        'fecha_fin'    => null,
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    // Un solo día — el de la llegada (2026-09-04). Antes esto recorría un
    // rango creando una fila por día y recortaba domingos; ahora la
    // permanencia la decide el encargado al registrar la salida.
    $resultado = $this->servicio->agendarLote(
        maquinaIds: [$excavadora->id, $vibro->id],
        proyectoId: $obra->id,
        dia: $dia->toDateString(),
        horaEntrada: '08:00:00',
    );

    expect($resultado['creados'])->toBe(1)
        ->and($resultado['saltados'])->toHaveCount(1)
        ->and($resultado['saltados'][0])->toContain('mantenimiento');
});

test('una máquina que ya está en otra obra no se puede agendar', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'RETRO 416']);
    $primera = Proyecto::factory()->enEjecucion()->create(['nombre' => 'LOS PINOS']);
    $segunda = Proyecto::factory()->enEjecucion()->create(['nombre' => 'LAS PALMAS']);

    $this->servicio->agendar($maquina->id, $primera->id, today()->addDay()->toDateString());

    // Sin fecha de salida, la máquina sigue comprometida: mandarla a otra
    // obra al día siguiente la duplicaría en dos sitios a la vez.
    expect(fn () => $this->servicio->agendar($maquina->id, $segunda->id, today()->addDays(2)->toDateString()))
        ->toThrow(AgendaInvalidaException::class, 'LOS PINOS');
});

test('NOTIFICA: al agendar, los encargados de la obra reciben campanita con máquina, fechas y llegada', function (): void {
    $encargado = User::factory()->create();
    $otro = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado);
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320D']);

    $this->servicio->agendarLote(
        maquinaIds: [$maquina->id],
        proyectoId: $obra->id,
        dia: today()->addDay()->toDateString(),
        horaEntrada: '08:00:00',
    );

    // UNA campanita por lote (no una por día), con el detalle completo.
    expect($encargado->notifications()->count())->toBe(1)
        ->and($otro->notifications()->count())->toBe(0);

    $data = $encargado->notifications()->first()->data;
    $texto = json_encode($data);

    expect($texto)->toContain('EXCAVADORA CAT 320D')
        ->and($texto)->toContain('8:00 AM');
});

test('NOTIFICA: el actor que agenda no se auto-notifica', function (): void {
    $encargado = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado);
    $maquina = Maquina::factory()->create();

    $this->servicio->agendarLote(
        maquinaIds: [$maquina->id],
        proyectoId: $obra->id,
        dia: today()->addDay()->toDateString(),
        userId: $encargado->id,
        horaEntrada: '08:00:00',
    );

    expect($encargado->notifications()->count())->toBe(0);
});

test('obra no viva y máquina de baja se rechazan', function (): void {
    $maquina = Maquina::factory()->create();
    $obraBorrador = Proyecto::factory()->create(); // borrador
    $obraViva = Proyecto::factory()->enEjecucion()->create();
    $deBaja = Maquina::factory()->create(['estado' => EstadoMaquina::Baja]);

    expect(fn () => $this->servicio->agendar($maquina->id, $obraBorrador->id, today()->addDay()->toDateString()))
        ->toThrow(AgendaInvalidaException::class, 'no está en ejecución');

    expect(fn () => $this->servicio->agendar($deBaja->id, $obraViva->id, today()->addDay()->toDateString()))
        ->toThrow(AgendaInvalidaException::class, 'baja');
});
