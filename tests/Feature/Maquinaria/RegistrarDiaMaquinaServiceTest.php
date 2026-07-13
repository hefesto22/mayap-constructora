<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\ConsumoCombustible;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
use App\Models\Proyecto;
use App\Services\Maquinaria\RegistrarDiaMaquinaService;

/*
|--------------------------------------------------------------------------
| Captura del día — planilla rápida: partes + combustible en un guardado.
|--------------------------------------------------------------------------
| Orquesta RegistrarParteService y RegistrarConsumoCombustibleService.
| Cada fila es independiente: la que falla se salta y se reporta.
*/

beforeEach(function (): void {
    $this->servicio = app(RegistrarDiaMaquinaService::class);
});

/**
 * @param array<string, mixed> $extra
 */
function capturaDiaAsignacionActiva(array $extra = []): AsignacionMaquina
{
    return AsignacionMaquina::factory()->create(array_merge([
        'maquina_id'          => Maquina::factory()->create(['jornada_horas' => '8.00'])->id,
        'proyecto_id'         => Proyecto::factory()->enEjecucion()->create()->id,
        'tarifa_hora_pactada' => '350.00',
        'fecha_inicio'        => today()->subDays(5)->toDateString(),
        'fecha_fin'           => null,
        'estado'              => EstadoAsignacion::Activa,
    ], $extra));
}

test('GOLDEN: un guardado registra horas Y combustible de varias máquinas, con costos correctos', function (): void {
    $a = capturaDiaAsignacionActiva();
    $b = capturaDiaAsignacionActiva();

    $resultado = $this->servicio->capturar(today()->toDateString(), [
        ['asignacion_id' => $a->id, 'horas' => '8', 'litros' => '40', 'precio_litro' => '40.50'],
        ['asignacion_id' => $b->id, 'horas' => '6.5', 'litros' => null, 'precio_litro' => null],
    ]);

    expect($resultado['partes'])->toBe(2)
        ->and($resultado['consumos'])->toBe(1)
        ->and($resultado['saltados'])->toBe([]);

    $parte = ParteTrabajo::where('asignacion_maquina_id', $a->id)->firstOrFail();
    expect($parte->costo_cache)->toBe('2800.00'); // 8h × 350

    $consumo = ConsumoCombustible::where('asignacion_maquina_id', $a->id)->firstOrFail();
    // Litros Y lempiras guardados por separado (litros para la orden de
    // compra a la gasolinera, lempiras como referencia de costo).
    expect($consumo->cantidad_litros)->toBe('40.00')
        ->and($consumo->precio_litro)->toBe('40.5000') // decimal(_,4) en DB
        ->and($consumo->costo_cache)->toBe('1620.00'); // 40 × 40.50
});

test('filas vacías se ignoran; la que falla se salta y reporta sin frenar al resto', function (): void {
    $ok = capturaDiaAsignacionActiva();
    $sinMotivo = capturaDiaAsignacionActiva();

    $resultado = $this->servicio->capturar(today()->toDateString(), [
        // Vacía: la máquina no trabajó — no genera nada.
        ['asignacion_id' => $ok->id, 'horas' => null, 'litros' => null],
        // 10h > jornada 8h SIN motivo → esta fila se salta...
        ['asignacion_id' => $sinMotivo->id, 'horas' => '10'],
        // ...pero el combustible sin precio también reporta, y esta sí pasa:
        ['asignacion_id' => $ok->id, 'horas' => '4', 'litros' => '20', 'precio_litro' => null],
    ]);

    expect($resultado['partes'])->toBe(1)
        ->and($resultado['consumos'])->toBe(0)
        ->and($resultado['saltados'])->toHaveCount(2)
        ->and(implode(' ', $resultado['saltados']))->toContain('motivo')->toContain('precio');
});

test('filasDelDia precarga asignaciones activas con horas de la agenda y marca lo ya registrado', function (): void {
    $asignacion = capturaDiaAsignacionActiva();

    // Agendada hoy 6.5h para esa máquina+obra.
    AgendaMaquina::factory()->create([
        'maquina_id'      => $asignacion->maquina_id,
        'proyecto_id'     => $asignacion->proyecto_id,
        'fecha'           => today()->toDateString(),
        'horas_previstas' => '6.50',
    ]);

    // Ya tiene combustible registrado hoy (referencia de último precio).
    ConsumoCombustible::factory()->create([
        'asignacion_maquina_id' => $asignacion->id,
        'fecha'                 => today()->toDateString(),
        'cantidad_litros'       => '10.00',
        'precio_litro'          => '39.75',
        'costo_cache'           => '397.50',
    ]);

    $filas = $this->servicio->filasDelDia(today()->toDateString());

    expect($filas)->toHaveCount(1)
        ->and($filas[0]['asignacion_id'])->toBe($asignacion->id)
        ->and($filas[0]['etiqueta'])->toContain($asignacion->maquina->nombre)
        ->and($filas[0]['horas'])->toBe('6.5')           // prellenado de la agenda
        ->and($filas[0]['precio_litro'])->toBe('39.75')  // último precio usado
        ->and($filas[0]['ya_registrado'])->toContain('combustible');
});
