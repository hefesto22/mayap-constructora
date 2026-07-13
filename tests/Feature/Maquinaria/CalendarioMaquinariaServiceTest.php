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
        ->and($eventos[0]['title'])->toContain('📌')->toContain('desde 05/07')
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
    expect($conRango['title'])->toContain('🔧')->toContain('RETROEXCAVADORA JD')
        ->and($conRango['color'])->toBe('#d97706')
        ->and($conRango['end'])->toBe('2026-07-15');

    $abierto = $porInicio('2026-07-09');
    expect($abierto['title'])->toContain('En mantenimiento desde 09/07')
        ->and($abierto)->not->toHaveKey('end'); // marcador, no barra
});

test('la agenda programada sale en azul con sus horas previstas y respeta filtros', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obraA = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA ALFA']);
    $obraB = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA BETA']);

    AgendaMaquina::factory()->create([
        'maquina_id' => $maquina->id, 'proyecto_id' => $obraA->id,
        'fecha'      => '2026-07-15', 'horas_previstas' => '4.00',
    ]);
    AgendaMaquina::factory()->create([
        'maquina_id' => $maquina->id, 'proyecto_id' => $obraB->id,
        'fecha'      => '2026-07-16', 'horas_previstas' => '6.50',
    ]);

    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31');
    expect($eventos)->toHaveCount(2)
        ->and($eventos[0]['title'])->toContain('🗓')->toContain('OBRA ALFA')->toContain('4h prog.')
        ->and($eventos[0]['color'])->toBe('#2563eb')
        ->and($eventos[1]['title'])->toContain('6.5h prog.');

    // Filtro por obra:
    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31', proyectoId: $obraB->id);
    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]['title'])->toContain('OBRA BETA');
});
