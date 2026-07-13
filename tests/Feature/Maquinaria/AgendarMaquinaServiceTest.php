<?php

declare(strict_types=1);

use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Maquinaria\AgendarMaquinaService;

/*
|--------------------------------------------------------------------------
| Agendar máquina — compromisos futuros por día y horas.
|--------------------------------------------------------------------------
| Única puerta de creación: valida fecha, horas, obra viva, máquina no
| dada de baja, choque con mantenimiento y duplicados.
*/

beforeEach(function (): void {
    $this->servicio = app(AgendarMaquinaService::class);
});

test('GOLDEN: agenda una máquina a una obra viva en fecha futura con horas', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obra = Proyecto::factory()->enEjecucion()->create();

    $agendado = $this->servicio->agendar(
        maquinaId: $maquina->id,
        proyectoId: $obra->id,
        fecha: today()->addDays(5)->toDateString(),
        horasPrevistas: '4.50',
        notas: 'MEDIO DIA EN ZANJEO',
        userId: null,
    );

    expect($agendado->exists)->toBeTrue()
        ->and($agendado->horas_previstas)->toBe('4.50')
        ->and($agendado->fecha->toDateString())->toBe(today()->addDays(5)->toDateString());
});

test('no se agenda en el pasado ni con horas fuera de rango', function (): void {
    $maquina = Maquina::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();

    expect(fn () => $this->servicio->agendar($maquina->id, $obra->id, today()->subDay()->toDateString(), '8'))
        ->toThrow(AgendaInvalidaException::class, 'pasado');

    expect(fn () => $this->servicio->agendar($maquina->id, $obra->id, today()->addDay()->toDateString(), '0'))
        ->toThrow(AgendaInvalidaException::class, 'horas');

    expect(fn () => $this->servicio->agendar($maquina->id, $obra->id, today()->addDay()->toDateString(), '25'))
        ->toThrow(AgendaInvalidaException::class, 'horas');
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

    expect(fn () => $this->servicio->agendar($maquina->id, $obra->id, today()->addDays(10)->toDateString(), '8'))
        ->toThrow(AgendaInvalidaException::class, 'mantenimiento');

    // Hoy (antes del mantenimiento) sí se puede.
    $agendado = $this->servicio->agendar($maquina->id, $obra->id, today()->toDateString(), '8');
    expect($agendado->exists)->toBeTrue();
});

test('no se duplica la misma máquina+obra+fecha, pero sí puede ir a OTRA obra ese día', function (): void {
    $maquina = Maquina::factory()->create();
    $obraA = Proyecto::factory()->enEjecucion()->create();
    $obraB = Proyecto::factory()->enEjecucion()->create();
    $fecha = today()->addDays(3)->toDateString();

    $this->servicio->agendar($maquina->id, $obraA->id, $fecha, '4');

    expect(fn () => $this->servicio->agendar($maquina->id, $obraA->id, $fecha, '4'))
        ->toThrow(AgendaInvalidaException::class, 'ya está agendada');

    // Mañana obra A de nuevo y el mismo día obra B: ambos válidos.
    $otraObra = $this->servicio->agendar($maquina->id, $obraB->id, $fecha, '4');
    expect($otraObra->exists)->toBeTrue();
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
        horasPrevistas: '8',
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
        '8',
    ))->toThrow(AgendaInvalidaException::class, 'invertido');

    expect(fn () => $this->servicio->agendarLote(
        [$maquina->id],
        $obra->id,
        today()->toDateString(),
        today()->addDays(40)->toDateString(),
        '8',
    ))->toThrow(AgendaInvalidaException::class, '31');
});

test('obra no viva y máquina de baja se rechazan', function (): void {
    $maquina = Maquina::factory()->create();
    $obraBorrador = Proyecto::factory()->create(); // borrador
    $obraViva = Proyecto::factory()->enEjecucion()->create();
    $deBaja = Maquina::factory()->create(['estado' => EstadoMaquina::Baja]);

    expect(fn () => $this->servicio->agendar($maquina->id, $obraBorrador->id, today()->addDay()->toDateString(), '8'))
        ->toThrow(AgendaInvalidaException::class, 'no está en ejecución');

    expect(fn () => $this->servicio->agendar($deBaja->id, $obraViva->id, today()->addDay()->toDateString(), '8'))
        ->toThrow(AgendaInvalidaException::class, 'baja');
});
