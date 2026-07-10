<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMantenimiento;
use App\Models\AsignacionMaquina;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Maquinaria\CalendarioMaquinariaService;

/*
|--------------------------------------------------------------------------
| Calendario de maquinaria (G3) — eventos para FullCalendar.
|--------------------------------------------------------------------------
| Verde = en obra, ámbar = mantenimiento, gris = finalizada. Los huecos
| son máquina libre: la oportunidad de alquiler.
*/

beforeEach(function (): void {
    $this->servicio = app(CalendarioMaquinariaService::class);
});

test('la asignación activa del rango sale en verde con máquina y obra; la abierta ocupa hasta el fin visible', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obra = Proyecto::factory()->enEjecucion()->create(['nombre' => 'ALCANTARILLADO NORTE']);

    AsignacionMaquina::factory()->create([
        'maquina_id'          => $maquina->id,
        'proyecto_id'         => $obra->id,
        'tarifa_hora_pactada' => '350.00',
        'fecha_inicio'        => '2026-07-05',
        'fecha_fin'           => null, // abierta: sigue en obra
        'estado'              => EstadoAsignacion::Activa,
    ]);

    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31');

    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]['title'])->toContain('EXCAVADORA CAT 320')->toContain('ALCANTARILLADO NORTE')
        ->and($eventos[0]['color'])->toBe('#16a34a')
        ->and($eventos[0]['start'])->toBe('2026-07-05')
        ->and($eventos[0]['end'])->toBe('2026-07-31');
});

test('lo que no toca el rango queda fuera y el filtro por máquina aísla la suya', function (): void {
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
        ->and($eventos[0]['end'])->toBe('2026-07-21'); // fin EXCLUSIVO (+1 día)

    // Filtro por la excavadora en un rango que cubre ambos:
    $eventos = $this->servicio->eventos('2026-06-01', '2026-07-31', maquinaId: $excavadora->id);
    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]['title'])->toContain('EXCAVADORA')
        ->and($eventos[0]['color'])->toBe('#9ca3af'); // finalizada = gris
});

test('el mantenimiento sale en ámbar con su llave', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'RETROEXCAVADORA JD']);

    MantenimientoMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'fecha_inicio' => '2026-07-12',
        'fecha_fin'    => '2026-07-14',
        'estado'       => EstadoMantenimiento::EnProceso,
    ]);

    $eventos = $this->servicio->eventos('2026-07-01', '2026-07-31');

    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]['title'])->toContain('🔧')->toContain('RETROEXCAVADORA JD')
        ->and($eventos[0]['color'])->toBe('#d97706')
        ->and($eventos[0]['end'])->toBe('2026-07-15');
});
