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

test('no se duplica la misma máquina+obra+fecha, pero sí puede ir a OTRA obra ese día', function (): void {
    $maquina = Maquina::factory()->create();
    $obraA = Proyecto::factory()->enEjecucion()->create();
    $obraB = Proyecto::factory()->enEjecucion()->create();
    $fecha = today()->addDays(3)->toDateString();

    $this->servicio->agendar($maquina->id, $obraA->id, $fecha, horaEntrada: '08:00:00');

    expect(fn () => $this->servicio->agendar($maquina->id, $obraA->id, $fecha, horaEntrada: '14:00:00'))
        ->toThrow(AgendaInvalidaException::class, 'ya está agendada');

    // Sale de la obra A y entra a la B más tarde ese mismo día: válido.
    // El criterio de horarios es de quien agenda (ve los compromisos en
    // el formulario) — el sistema no estima cuánto trabajará.
    $otraObra = $this->servicio->agendar($maquina->id, $obraB->id, $fecha, horaEntrada: '13:00:00');
    expect($otraObra->exists)->toBeTrue()
        ->and($otraObra->horaEntradaCorta())->toBe('13:00');
});

test('LOTE: varias máquinas × rango de días en un guardado, excluye domingos y salta choques sin abortar', function (): void {
    $excavadora = Maquina::factory()->create(['nombre' => 'EXCAVADORA']);
    $vibro = Maquina::factory()->create(['nombre' => 'VIBRO']);
    $obra = Proyecto::factory()->enEjecucion()->create();

    // Lunes a domingo próximos (7 días, 1 es domingo).
    $lunes = today()->addWeek()->startOfWeek();
    $domingo = $lunes->copy()->addDays(6);

    // La vibro entra al taller el miércoles de esa semana (abierto).
    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $vibro->id,
        'fecha_inicio' => $lunes->copy()->addDays(2)->toDateString(),
        'fecha_fin'    => null,
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    $resultado = $this->servicio->agendarLote(
        maquinaIds: [$excavadora->id, $vibro->id],
        proyectoId: $obra->id,
        desde: $lunes->toDateString(),
        hasta: $domingo->toDateString(),
        horaEntrada: '08:00:00',
    );

    // Excavadora: 6 días hábiles (domingo excluido). Vibro: solo lun+mar
    // (desde el miércoles choca con el mantenimiento abierto).
    expect($resultado['creados'])->toBe(8)
        ->and($resultado['saltados'])->toHaveCount(4)
        ->and($resultado['saltados'][0])->toContain('mantenimiento');
});

test('LOTE: rango invertido o mayor a 31 días se rechaza completo', function (): void {
    $maquina = Maquina::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();

    expect(fn () => $this->servicio->agendarLote(
        [$maquina->id],
        $obra->id,
        today()->addDays(5)->toDateString(),
        today()->addDay()->toDateString(),
    ))->toThrow(AgendaInvalidaException::class, 'invertido');

    expect(fn () => $this->servicio->agendarLote(
        [$maquina->id],
        $obra->id,
        today()->toDateString(),
        today()->addDays(40)->toDateString(),
    ))->toThrow(AgendaInvalidaException::class, '31');
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
        desde: today()->addDay()->toDateString(),
        hasta: today()->addDays(2)->toDateString(),
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
        desde: today()->addDay()->toDateString(),
        hasta: today()->addDay()->toDateString(),
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
