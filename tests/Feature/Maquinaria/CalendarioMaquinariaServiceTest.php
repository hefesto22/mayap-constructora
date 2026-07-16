<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMantenimiento;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
use App\Models\Proyecto;
use App\Services\Maquinaria\CalendarioMaquinariaService;

/*
|--------------------------------------------------------------------------
| Calendario de maquinaria (G3) — eventos para FullCalendar.
|--------------------------------------------------------------------------
| Días y horas reales, no barras infinitas: verde = parte trabajado,
| azul = agenda programada, teal = asignación (rango o marcador de inicio),
| ámbar = mantenimiento, gris = finalizada. El hueco = máquina libre.
*/

beforeEach(function (): void {
    $this->servicio = app(CalendarioMaquinariaService::class);
});

test('el parte de trabajo sale como evento verde de UN día con sus horas', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obra = Proyecto::factory()->enEjecucion()->create(['nombre' => 'ALCANTARILLADO NORTE']);

    $asignacion = AsignacionMaquina::factory()->create([
        'maquina_id'          => $maquina->id,
        'proyecto_id'         => $obra->id,
        'tarifa_hora_pactada' => '350.00',
        'fecha_inicio'        => '2026-07-05',
        'fecha_fin'           => '2026-07-20',
        'estado'              => EstadoAsignacion::Activa,
    ]);

    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacion->id,
        'fecha'                 => '2026-07-10',
        'horas'                 => '8.00',
        'horas_extra'           => '2.00',
        'motivo_horas_extra'    => 'TERMINAR TRAMO DE ZANJA', // CHECK: extra exige motivo
        'tarifa_hora_aplicada'  => '350.00',
        'costo_cache'           => '3500.00',
    ]);

    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31');
    $partes = array_values(array_filter($eventos, fn (array $e): bool => str_starts_with((string) $e['id'], 'parte-')));

    expect($partes)->toHaveCount(1)
        ->and($partes[0]['title'])->toContain('EXCAVADORA CAT 320')
        ->toContain('ALCANTARILLADO NORTE')
        ->toContain('8h')
        ->toContain('(+2h ext)')
        ->and($partes[0]['start'])->toBe('2026-07-10')
        ->and($partes[0]['color'])->toBe('#16a34a')
        ->and($partes[0])->not->toHaveKey('end'); // 1 día, no barra
});

test('asignación ABIERTA = marcador de un día en su inicio, nunca barra hasta fin de mes', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obra = Proyecto::factory()->enEjecucion()->create(['nombre' => 'ALCANTARILLADO NORTE']);

    AsignacionMaquina::factory()->create([
        'maquina_id'          => $maquina->id,
        'proyecto_id'         => $obra->id,
        'tarifa_hora_pactada' => '350.00',
        'fecha_inicio'        => '2026-07-05',
        'fecha_fin'           => null, // abierta
        'estado'              => EstadoAsignacion::Activa,
    ]);

    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31');

    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]['title'])->toContain('desde 05/07')
        ->and($eventos[0]['start'])->toBe('2026-07-05')
        ->and($eventos[0])->not->toHaveKey('end')
        ->and($eventos[0]['color'])->toBe('#0d9488');
});

test('asignación con RANGO definido sí es barra (teal activa, gris finalizada) y el filtro por máquina aísla', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create();
    $excavadora = Maquina::factory()->create(['nombre' => 'EXCAVADORA']);
    $vibro = Maquina::factory()->create(['nombre' => 'VIBROCOMPACTADORA']);

    // Junio (fuera del rango consultado) y julio (dentro).
    AsignacionMaquina::factory()->create([
        'maquina_id'          => $excavadora->id, 'proyecto_id' => $obra->id,
        'tarifa_hora_pactada' => '350.00',
        'fecha_inicio'        => '2026-06-01', 'fecha_fin' => '2026-06-15',
        'estado'              => EstadoAsignacion::Finalizada,
    ]);
    AsignacionMaquina::factory()->create([
        'maquina_id'          => $vibro->id, 'proyecto_id' => $obra->id,
        'tarifa_hora_pactada' => '275.00',
        'fecha_inicio'        => '2026-07-10', 'fecha_fin' => '2026-07-20',
        'estado'              => EstadoAsignacion::Activa,
    ]);

    // Solo julio:
    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31');
    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]['title'])->toContain('VIBROCOMPACTADORA')
        ->and($eventos[0]['end'])->toBe('2026-07-21') // fin EXCLUSIVO (+1 día)
        ->and($eventos[0]['color'])->toBe('#0d9488');

    // Filtro por la excavadora en un rango que cubre ambos:
    $eventos = $this->servicio->eventos('2026-06-01', '2026-07-31', maquinaId: $excavadora->id);
    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]['title'])->toContain('EXCAVADORA')
        ->and($eventos[0]['color'])->toBe('#9ca3af'); // finalizada = gris
});

test('mantenimiento con rango = barra ámbar; SIN fecha fin = marcador de un día "desde"', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'RETROEXCAVADORA JD']);
    $otra = Maquina::factory()->create(['nombre' => 'VOLQUETA MACK']);

    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'fecha_inicio' => '2026-07-12',
        'fecha_fin'    => '2026-07-14',
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);
    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $otra->id,
        'fecha_inicio' => '2026-07-09',
        'fecha_fin'    => null, // abierto: la reparación sigue
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31');

    $porInicio = fn (string $fecha): array => array_values(
        array_filter($eventos, fn (array $e): bool => $e['start'] === $fecha)
    )[0];

    $conRango = $porInicio('2026-07-12');
    expect($conRango['title'])->toContain('RETROEXCAVADORA JD')
        ->and($conRango['color'])->toBe('#d97706')
        ->and($conRango['end'])->toBe('2026-07-15');

    $abierto = $porInicio('2026-07-09');
    expect($abierto['title'])->toContain('En mantenimiento desde 09/07')
        ->and($abierto)->not->toHaveKey('end'); // marcador, no barra
});

test('el azul agendado DESAPARECE cuando ya existe el parte real de ese día (plan cumplido)', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obra = Proyecto::factory()->enEjecucion()->create();

    $asignacion = AsignacionMaquina::factory()->create([
        'maquina_id'          => $maquina->id, 'proyecto_id' => $obra->id,
        'tarifa_hora_pactada' => '350.00',
        'fecha_inicio'        => '2026-07-01', 'fecha_fin' => '2026-07-31',
        'estado'              => EstadoAsignacion::Activa,
    ]);

    AgendaMaquina::factory()->create([
        'maquina_id' => $maquina->id, 'proyecto_id' => $obra->id,
        'fecha'      => '2026-07-15',
    ]);

    // Sin parte: el azul aparece.
    $azules = array_filter(
        $this->servicio->eventos('2026-07-01', '2026-07-31'),
        fn (array $e): bool => str_starts_with((string) $e['id'], 'agenda-'),
    );
    expect($azules)->toHaveCount(1);

    // Llega la realidad (parte verde del mismo día) → el azul se retira.
    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacion->id,
        'fecha'                 => '2026-07-15',
        'horas'                 => '7.50', 'horas_extra' => '0.00',
        'tarifa_hora_aplicada'  => '350.00', 'costo_cache' => '2625.00',
    ]);

    $azules = array_filter(
        $this->servicio->eventos('2026-07-01', '2026-07-31'),
        fn (array $e): bool => str_starts_with((string) $e['id'], 'agenda-'),
    );
    expect($azules)->toHaveCount(0);
});

test('la agenda programada sale en azul con su hora de llegada y respeta filtros', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obraA = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA ALFA']);
    $obraB = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA BETA']);

    AgendaMaquina::factory()->create([
        'maquina_id'   => $maquina->id, 'proyecto_id' => $obraA->id,
        'fecha'        => '2026-07-15',
        'hora_entrada' => '07:30:00',
    ]);
    AgendaMaquina::factory()->create([
        'maquina_id'   => $maquina->id, 'proyecto_id' => $obraB->id,
        'fecha'        => '2026-07-16',
        'hora_entrada' => null,
    ]);

    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31');
    expect($eventos)->toHaveCount(2)
        ->and($eventos[0]['title'])->toContain('OBRA ALFA')
        ->toContain('llega 7:30 AM') // a qué hora llega, en AM/PM
        ->and($eventos[0]['color'])->toBe('#2563eb')
        ->and($eventos[1]['title'])->toContain('OBRA BETA')->not->toContain('llega');

    // Filtro por obra:
    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31', proyectoId: $obraB->id);
    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]['title'])->toContain('OBRA BETA');
});

test('ALCANCE ENCARGADO: soloProyectos acota agenda/asignaciones a SUS obras y oculta los mantenimientos', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $mia = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA MIA']);
    $ajena = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA AJENA']);

    AgendaMaquina::factory()->create([
        'maquina_id'   => $maquina->id, 'proyecto_id' => $mia->id,
        'fecha'        => '2026-07-15',
        'hora_entrada' => '08:00:00',
    ]);
    AgendaMaquina::factory()->create([
        'maquina_id'   => $maquina->id, 'proyecto_id' => $ajena->id,
        'fecha'        => '2026-07-16',
        'hora_entrada' => '08:00:00',
    ]);

    // El taller no pertenece a una obra: en la vista acotada no aparece.
    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'fecha_inicio' => '2026-07-20',
        'fecha_fin'    => '2026-07-22',
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31', soloProyectos: [$mia->id]);

    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]['title'])->toContain('OBRA MIA');

    // Sin límite (maquinaria/gerencia) se ve TODO: 2 agendas + taller.
    expect($this->servicio->eventos('2026-07-01', '2026-07-31'))->toHaveCount(3);
});

test('la asignación FINALIZADA de un solo día con parte ya registrado se OCULTA (el verde cuenta la historia)', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obra = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA UNICA']);

    // La administrativa: un solo día, finalizada (p. ej. la automática
    // que crea "Registrar jornada" desde el calendario).
    $deUnDia = AsignacionMaquina::factory()->create([
        'maquina_id'          => $maquina->id,
        'proyecto_id'         => $obra->id,
        'tarifa_hora_pactada' => '350.00',
        'fecha_inicio'        => '2026-07-10',
        'fecha_fin'           => '2026-07-10',
        'estado'              => EstadoAsignacion::Finalizada,
    ]);

    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $deUnDia->id,
        'fecha'                 => '2026-07-10',
        'horas'                 => '6.00',
        'horas_extra'           => '0.00',
        'tarifa_hora_aplicada'  => '350.00',
        'costo_cache'           => '2100.00',
    ]);

    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31');
    $asignaciones = array_values(array_filter($eventos, fn (array $e): bool => str_starts_with((string) $e['id'], 'asignacion-')));
    $partes = array_values(array_filter($eventos, fn (array $e): bool => str_starts_with((string) $e['id'], 'parte-')));

    // La barra gris de un día desaparece; el parte verde queda.
    expect($asignaciones)->toHaveCount(0)
        ->and($partes)->toHaveCount(1)
        ->and($partes[0]['title'])->toContain('6h');
});
