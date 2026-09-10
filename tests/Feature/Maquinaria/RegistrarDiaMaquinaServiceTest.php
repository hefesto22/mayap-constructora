<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Enums\ModalidadTrabajo;
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
        'maquina_id'          => Maquina::factory()->create(['horas_dia_renta' => '8.00'])->id,
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

    // Sin tarifa por viaje pactada, la fila de la volqueta no puede costear
    // y se salta. (Antes el caso de prueba era "10 h sin motivo de horas
    // extra", que desde 2026-09-04 ya no falla: el parte dejó de calcular
    // horas extra porque la jornada de la máquina no era un dato real.)
    $sinTarifa = capturaDiaAsignacionActiva();
    $sinTarifa->forceFill(['tarifa_viaje_pactada' => null])->save();

    $resultado = $this->servicio->capturar(today()->toDateString(), [
        // Vacía: la máquina no trabajó — no genera nada.
        ['asignacion_id' => $ok->id, 'horas' => null, 'litros' => null],
        // Cobra por viajes pero nadie pactó la tarifa → esta fila se salta...
        ['asignacion_id' => $sinTarifa->id, 'horas' => '10', 'modalidad' => 'viajes', 'viajes' => 3],
        // ...pero el combustible sin precio también reporta, y esta sí pasa:
        ['asignacion_id' => $ok->id, 'horas' => '4', 'litros' => '20', 'precio_litro' => null],
    ]);

    expect($resultado['partes'])->toBe(1)
        ->and($resultado['consumos'])->toBe(0)
        ->and($resultado['saltados'])->toHaveCount(2)
        ->and(implode(' ', $resultado['saltados']))->toContain('tarifa')->toContain('precio');
});

test('filasDelDia precarga asignaciones activas con horas vacías y marca lo ya registrado', function (): void {
    $asignacion = capturaDiaAsignacionActiva();

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
        ->and($filas[0]['horas'])->toBeNull()            // las reales las escribe quien captura
        ->and($filas[0]['precio_litro'])->toBe('39.75')  // último precio usado
        ->and($filas[0]['ya_registrado'])->toContain('combustible');
});

/*
| MODALIDAD (2026-08-16): la captura nacía SIEMPRE en horas, así que la
| renta por viajes o por km cobraba 0 de excedente y el kilometraje de
| la máquina nunca avanzaba.
*/

test('MODALIDAD: la jornada por viajes guarda los viajes, no solo las horas', function (): void {
    $asignacion = capturaDiaAsignacionActiva();

    $resultado = $this->servicio->capturar(today()->toDateString(), [[
        'asignacion_id' => $asignacion->id,
        'horas'         => '8',
        'modalidad'     => ModalidadTrabajo::Viajes->value,
        'viajes'        => '10',
    ]]);

    expect($resultado['partes'])->toBe(1)
        ->and($resultado['saltados'])->toBe([]);

    $parte = ParteTrabajo::where('asignacion_maquina_id', $asignacion->id)->firstOrFail();

    expect($parte->modalidad)->toBe(ModalidadTrabajo::Viajes)
        ->and($parte->viajes)->toBe(10);
});

test('MODALIDAD: la jornada por km guarda los km y los suma al kilometraje de la máquina', function (): void {
    $maquina = Maquina::factory()->create(['horas_dia_renta' => '8.00', 'kilometraje_actual' => '1000.00']);
    $asignacion = capturaDiaAsignacionActiva(['maquina_id' => $maquina->id]);

    $resultado = $this->servicio->capturar(today()->toDateString(), [[
        'asignacion_id' => $asignacion->id,
        'horas'         => '8',
        'modalidad'     => ModalidadTrabajo::Kilometraje->value,
        'km_recorridos' => '120.5',
    ]]);

    expect($resultado['saltados'])->toBe([]);

    $parte = ParteTrabajo::where('asignacion_maquina_id', $asignacion->id)->firstOrFail();

    expect($parte->modalidad)->toBe(ModalidadTrabajo::Kilometraje)
        ->and($parte->km_recorridos)->toBe('120.50')
        ->and($maquina->fresh()->kilometraje_actual)->toBe('1120.50');
});

test('MODALIDAD: sin modalidad en la fila el día sigue siendo por horas', function (): void {
    $asignacion = capturaDiaAsignacionActiva();

    $this->servicio->capturar(today()->toDateString(), [[
        'asignacion_id' => $asignacion->id,
        'horas'         => '8',
    ]]);

    $parte = ParteTrabajo::where('asignacion_maquina_id', $asignacion->id)->firstOrFail();

    expect($parte->modalidad)->toBe(ModalidadTrabajo::Horas)
        ->and($parte->viajes)->toBeNull();
});
