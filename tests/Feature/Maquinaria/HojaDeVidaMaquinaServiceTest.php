<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Models\AsignacionMaquina;
use App\Models\ConsumoCombustible;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
use App\Models\Proyecto;
use App\Services\Maquinaria\HojaDeVidaMaquinaService;

/*
|--------------------------------------------------------------------------
| Hoja de vida de la máquina (G5) — rentabilidad e historial.
|--------------------------------------------------------------------------
| utilidad = ingresos por partes − combustible. Todo agregado en SQL.
*/

beforeEach(function (): void {
    $this->servicio = app(HojaDeVidaMaquinaService::class);
});

test('GOLDEN: junta horas, ingresos, combustible y utilidad de TODAS sus asignaciones', function (): void {
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320']);
    $obraA = Proyecto::factory()->enEjecucion()->create();
    $obraB = Proyecto::factory()->enEjecucion()->create();

    $asignacionA = AsignacionMaquina::factory()->create([
        'maquina_id'          => $maquina->id, 'proyecto_id' => $obraA->id,
        'tarifa_hora_pactada' => '350.00',
        'fecha_inicio'        => '2026-06-01', 'fecha_fin' => '2026-06-30',
        'estado'              => EstadoAsignacion::Finalizada,
    ]);
    $asignacionB = AsignacionMaquina::factory()->create([
        'maquina_id'          => $maquina->id, 'proyecto_id' => $obraB->id,
        'tarifa_hora_pactada' => '300.00',
        'fecha_inicio'        => '2026-07-01', 'fecha_fin' => null,
        'estado'              => EstadoAsignacion::Activa,
    ]);

    // Obra A: 10h + 5h @350 = 5,250 · combustible 20L → 800.
    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacionA->id,
        'horas'                 => '10.00', 'horas_extra' => '0.00',
        'tarifa_hora_aplicada'  => '350.00', 'costo_cache' => '3500.00',
    ]);
    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacionA->id,
        'horas'                 => '5.00', 'horas_extra' => '0.00',
        'tarifa_hora_aplicada'  => '350.00', 'costo_cache' => '1750.00',
    ]);
    ConsumoCombustible::factory()->create([
        'asignacion_maquina_id' => $asignacionA->id,
        'cantidad_litros'       => '20.00', 'precio_litro' => '40.00', 'costo_cache' => '800.00',
    ]);

    // Obra B: 8h @300 = 2,400 · combustible 10L → 400.
    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacionB->id,
        'horas'                 => '8.00', 'horas_extra' => '0.00',
        'tarifa_hora_aplicada'  => '300.00', 'costo_cache' => '2400.00',
    ]);
    ConsumoCombustible::factory()->create([
        'asignacion_maquina_id' => $asignacionB->id,
        'cantidad_litros'       => '10.00', 'precio_litro' => '40.00', 'costo_cache' => '400.00',
    ]);

    // Ruido: OTRA máquina con partes — no debe contaminar.
    $otra = AsignacionMaquina::factory()->create([
        'maquina_id'   => Maquina::factory()->create()->id,
        'proyecto_id'  => $obraA->id, 'tarifa_hora_pactada' => '500.00',
        'fecha_inicio' => '2026-07-01', 'estado' => EstadoAsignacion::Activa,
    ]);
    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $otra->id,
        'horas'                 => '99.00', 'horas_extra' => '0.00',
        'tarifa_hora_aplicada'  => '500.00', 'costo_cache' => '49500.00',
    ]);

    $resumen = $this->servicio->resumen($maquina);

    expect($resumen->horas)->toBe('23.00')
        ->and($resumen->ingresos)->toBe('7650.00')
        ->and($resumen->combustible)->toBe('1200.00')
        ->and($resumen->litros)->toBe('30.00')
        ->and($resumen->utilidad)->toBe('6450.00')
        ->and($resumen->margen)->toBe('84.31')
        ->and($resumen->totalAsignaciones)->toBe(2)
        ->and($resumen->conUtilidadPositiva())->toBeTrue();

    // Historial con totales por obra (la más reciente primero).
    $historial = $this->servicio->asignacionesConTotales($maquina);

    expect($historial)->toHaveCount(2)
        ->and($historial[0]->id)->toBe($asignacionB->id)
        ->and((string) $historial[0]->ingresos_total)->toBe('2400.00')
        ->and((string) $historial[1]->ingresos_total)->toBe('5250.00')
        ->and((string) $historial[1]->combustible_total)->toBe('800.00');
});

test('una máquina sin historia devuelve ceros sanos (sin divisiones rotas)', function (): void {
    $maquina = Maquina::factory()->create();

    $resumen = $this->servicio->resumen($maquina);

    expect($resumen->ingresos)->toBe('0')
        ->and($resumen->utilidad)->toBe('0.00')
        ->and($resumen->margen)->toBe('0.00')
        ->and($resumen->totalAsignaciones)->toBe(0)
        ->and($resumen->conUtilidadPositiva())->toBeTrue();
});
