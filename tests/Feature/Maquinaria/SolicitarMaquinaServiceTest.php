<?php

declare(strict_types=1);

use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoSolicitudMaquina;
use App\Enums\PrioridadSolicitud;
use App\Exceptions\Maquinaria\SolicitudInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\SolicitudMaquina;
use App\Models\User;
use App\Services\Maquinaria\SolicitarMaquinaService;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Solicitudes de maquinaria — el encargado pide, la agenda decide.
|--------------------------------------------------------------------------
| Disponible → nace Agendada con su agendado real. Ocupada → Pendiente
| con motivo y campanita al rol maquinaria. Todo queda como historial
| del proyecto (nunca se borra).
*/

beforeEach(function (): void {
    $this->servicio = app(SolicitarMaquinaService::class);
});

test('GOLDEN: máquina disponible → la solicitud nace AGENDADA con su agendado en el calendario', function (): void {
    $encargado = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado);
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320D']);
    $fecha = today()->addDays(2)->toDateString();

    $solicitud = $this->servicio->crear(
        proyectoId: $obra->id,
        maquinaId: $maquina->id,
        fechaDesde: $fecha,
        horaLlegada: '08:00',
        notas: 'ZANJEO SECTOR NORTE',
        userId: $encargado->id,
    );

    expect($solicitud->codigo)->toStartWith('SOLMAQ-'.now()->year)
        ->and($solicitud->estado)->toBe(EstadoSolicitudMaquina::Agendada)
        ->and($solicitud->agenda_maquina_id)->not->toBeNull()
        ->and($solicitud->resuelta_at)->not->toBeNull();

    // El agendado real existe: fecha y hora de llegada pedidas.
    $agendado = AgendaMaquina::findOrFail($solicitud->agenda_maquina_id);
    expect($agendado->fecha->toDateString())->toBe($fecha)
        ->and($agendado->horaEntradaCorta())->toBe('08:00')
        ->and($agendado->proyecto_id)->toBe($obra->id);
});

test('RANGO: "del lunes al miércoles" crea un agendado por día; lo que choca se salta y queda en el motivo', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();

    // Anclado a lunes-miércoles: sin domingos de por medio (el lote los
    // excluye por default y el conteo sería distinto según el día de hoy).
    $desde = today()->addWeek()->startOfWeek();
    $hasta = $desde->copy()->addDays(2);

    // El día de en medio está en taller: 2 de 3 días se agendan.
    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'fecha_inicio' => $desde->copy()->addDay()->toDateString(),
        'fecha_fin'    => $desde->copy()->addDay()->toDateString(),
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    $solicitud = $this->servicio->crear(
        proyectoId: $obra->id,
        maquinaId: $maquina->id,
        fechaDesde: $desde->toDateString(),
        horaLlegada: '08:00',
        fechaHasta: $hasta->toDateString(),
    );

    expect($solicitud->estado)->toBe(EstadoSolicitudMaquina::Agendada)
        ->and($solicitud->fecha_hasta->toDateString())->toBe($hasta->toDateString())
        ->and($solicitud->motivo)->toContain('2 día(s)')
        ->and($solicitud->motivo)->toContain('Saltados')
        ->and(AgendaMaquina::where('maquina_id', $maquina->id)->count())->toBe(2)
        ->and($solicitud->rangoParaEl())->toContain('al '.$hasta->format('d/m/Y'));
});

test('DOBLE USO: con la máquina YA comprometida ese día, la solicitud requiere autorización de maquinaria', function (): void {
    $obraA = Proyecto::factory()->enEjecucion()->create();
    $obraB = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();
    $jefe = User::factory()->create();
    $fecha = today()->addDays(2)->toDateString();

    // Día libre: la primera se agenda sola.
    expect($this->servicio->crear($obraA->id, $maquina->id, $fecha, '07:00')->estado)->toBe(EstadoSolicitudMaquina::Agendada);

    // La SEGUNDA del mismo día ya no decide sola: jornadas largas — nadie
    // garantiza que se desocupe. Autoriza maquinaria.
    $segunda = $this->servicio->crear($obraB->id, $maquina->id, $fecha, '15:00');

    // El motivo dice a qué HORA y a qué OBRA está comprometida —
    // maquinaria autoriza con los datos enfrente, no a ciegas.
    expect($segunda->estado)->toBe(EstadoSolicitudMaquina::Pendiente)
        ->and($segunda->motivo)->toContain('autorización de maquinaria')
        ->and($segunda->motivo)->toContain('llega 7:00 AM a '.$obraA->nombre)
        ->and(AgendaMaquina::where('maquina_id', $maquina->id)->count())->toBe(1);

    // Maquinaria autoriza el doble uso → la agenda manualmente (sin límite).
    $resuelta = $this->servicio->agendar($segunda, $fecha, userId: $jefe->id);

    expect($resuelta->estado)->toBe(EstadoSolicitudMaquina::Agendada)
        ->and(AgendaMaquina::where('maquina_id', $maquina->id)->count())->toBe(2);
});

test('OBVIO: la misma máquina a la MISMA obra el mismo día se rechaza sin crear la solicitud', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();
    $fecha = today()->addDays(2)->toDateString();

    $this->servicio->crear($obra->id, $maquina->id, $fecha, '08:00');

    expect(fn () => $this->servicio->crear($obra->id, $maquina->id, $fecha, '15:00'))
        ->toThrow(SolicitudInvalidaException::class, 'misma obra');

    // Ni solicitud fantasma ni pendiente sin sentido para maquinaria.
    expect(SolicitudMaquina::count())->toBe(1);
});

test('PRIORIDAD: la urgente se guarda y la campanita al rol maquinaria entra marcada URGENTE', function (): void {
    Role::firstOrCreate(['name' => 'maquinaria', 'guard_name' => 'web']);
    $jefe = User::factory()->create();
    $jefe->assignRole('maquinaria');

    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();
    $fecha = today()->addDay()->toDateString();

    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'fecha_inicio' => today()->toDateString(),
        'fecha_fin'    => null,
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    $solicitud = $this->servicio->crear(
        proyectoId: $obra->id,
        maquinaId: $maquina->id,
        fechaDesde: $fecha,
        horaLlegada: '08:00',
        prioridad: PrioridadSolicitud::Urgente,
    );

    expect($solicitud->prioridad)->toBe(PrioridadSolicitud::Urgente)
        ->and($solicitud->estado)->toBe(EstadoSolicitudMaquina::Pendiente);

    $data = json_encode($jefe->notifications()->first()->data);
    expect($data)->toContain('URGENTE');
});

test('máquina en taller ese día → nace PENDIENTE con el motivo y campanita al rol maquinaria', function (): void {
    Role::firstOrCreate(['name' => 'maquinaria', 'guard_name' => 'web']);
    $jefeMaquinaria = User::factory()->create();
    $jefeMaquinaria->assignRole('maquinaria');

    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create(['nombre' => 'RETRO JD 310']);
    $fecha = today()->addDays(2)->toDateString();

    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'fecha_inicio' => today()->toDateString(),
        'fecha_fin'    => null,
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    $solicitud = $this->servicio->crear($obra->id, $maquina->id, $fecha, '08:00');

    expect($solicitud->estado)->toBe(EstadoSolicitudMaquina::Pendiente)
        ->and($solicitud->agenda_maquina_id)->toBeNull()
        ->and($solicitud->motivo)->toContain('mantenimiento')
        ->and(AgendaMaquina::count())->toBe(0)
        ->and($jefeMaquinaria->notifications()->count())->toBe(1);
});

test('RESOLUCIÓN: maquinaria agenda la pendiente en otra fecha y el solicitante recibe el aviso', function (): void {
    $encargado = User::factory()->create();
    $jefe = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado);
    $maquina = Maquina::factory()->create();

    // En taller HOY y mañana no: la solicitud para hoy queda pendiente.
    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'fecha_inicio' => today()->toDateString(),
        'fecha_fin'    => today()->toDateString(),
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    $solicitud = $this->servicio->crear($obra->id, $maquina->id, today()->toDateString(), '08:00', userId: $encargado->id);
    expect($solicitud->estado)->toBe(EstadoSolicitudMaquina::Pendiente);

    // Maquinaria la resuelve para mañana a la 1:00 PM.
    $resuelta = $this->servicio->agendar(
        solicitud: $solicitud,
        fechaDesde: today()->addDay()->toDateString(),
        horaLlegada: '13:00',
        userId: $jefe->id,
    );

    expect($resuelta->estado)->toBe(EstadoSolicitudMaquina::Agendada)
        ->and($resuelta->resuelta_por_id)->toBe($jefe->id)
        ->and($resuelta->agendado->fecha->toDateString())->toBe(today()->addDay()->toDateString())
        ->and($resuelta->agendado->horaEntradaCorta())->toBe('13:00');

    // El encargado recibió el aviso de resolución (además del de
    // "maquinaria agendada a tu obra").
    expect($encargado->notifications()->count())->toBeGreaterThanOrEqual(1);
});

test('RECHAZO: queda en el historial con motivo y el solicitante se entera', function (): void {
    $encargado = User::factory()->create();
    $jefe = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado);
    $maquina = Maquina::factory()->create();

    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'fecha_inicio' => today()->toDateString(),
        'fecha_fin'    => null,
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    $solicitud = $this->servicio->crear($obra->id, $maquina->id, today()->addDay()->toDateString(), '08:00', userId: $encargado->id);

    $rechazada = $this->servicio->rechazar($solicitud, 'LA MAQUINA SEGUIRA EN TALLER TODA LA SEMANA', $jefe->id);

    expect($rechazada->estado)->toBe(EstadoSolicitudMaquina::Rechazada)
        ->and($rechazada->motivo)->toContain('TALLER')
        ->and($rechazada->resuelta_por_id)->toBe($jefe->id)
        ->and($encargado->notifications()->count())->toBeGreaterThanOrEqual(1);
});

test('una solicitud resuelta NO se puede volver a resolver (historial inmutable)', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();

    // Disponible → nace agendada (resuelta).
    $solicitud = $this->servicio->crear($obra->id, $maquina->id, today()->addDay()->toDateString(), '08:00');
    expect($solicitud->estado)->toBe(EstadoSolicitudMaquina::Agendada);

    expect(fn () => $this->servicio->rechazar($solicitud, 'YA NO'))
        ->toThrow(SolicitudInvalidaException::class, 'ya fue resuelta');

    expect(fn () => $this->servicio->agendar($solicitud, today()->addDays(3)->toDateString()))
        ->toThrow(SolicitudInvalidaException::class, 'ya fue resuelta');
});

test('la solicitud queda en el historial del proyecto (relaciones)', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();

    $this->servicio->crear($obra->id, $maquina->id, today()->addDay()->toDateString(), '08:00');

    expect($obra->solicitudesMaquina()->count())->toBe(1)
        ->and($obra->agendaMaquina()->count())->toBe(1);
});
