<?php

declare(strict_types=1);

use App\Enums\EstadoMaquina;
use App\Enums\MetodoCapturaHoras;
use App\Exceptions\Maquinaria\ParteInvalidoException;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
use App\Models\Proyecto;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\RegistrarParteService;

/*
|--------------------------------------------------------------------------
| Golden tests del parte de trabajo: cálculo de horas, extra y cobro.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = new RegistrarParteService;
    $this->asignar = new AsignarMaquinaService;
    $this->obra = Proyecto::factory()->create();
});

function asignacionConTarifa(AsignarMaquinaService $asignar, Proyecto $obra, float $tarifa, float $jornada = 8, float $horometro = 100): AsignacionMaquina
{
    $maquina = Maquina::factory()->create([
        'jornada_horas'    => $jornada,
        'horometro_actual' => $horometro,
        'estado'           => EstadoMaquina::Disponible->value,
    ]);

    return $asignar->asignar($maquina, $obra->id, tarifaPactada: (string) $tarifa);
}

test('GOLDEN: parte por horómetro calcula horas, costo y avanza el horómetro', function (): void {
    // Horómetro 100 → 108 = 8h. Tarifa 1500 → costo 12,000. Sin horas extra.
    $asignacion = asignacionConTarifa($this->asignar, $this->obra, tarifa: 1500, jornada: 8, horometro: 100);

    $parte = $this->service->registrarPorHorometro($asignacion, lecturaFinal: '108');

    expect($parte->codigo)->toStartWith('PART-')
        ->and($parte->metodo_captura)->toBe(MetodoCapturaHoras::Horometro)
        ->and($parte->horas)->toBe('8.00')
        ->and($parte->horas_extra)->toBe('0.00')
        ->and($parte->tarifa_hora_aplicada)->toBe('1500.00')
        ->and($parte->costo_cache)->toBe('12000.00')
        ->and($parte->asignacion->maquina->fresh()->horometro_actual)->toBe('108.00');
});

test('parte por horómetro usa el horómetro actual como lectura inicial por defecto', function (): void {
    $asignacion = asignacionConTarifa($this->asignar, $this->obra, tarifa: 1000, horometro: 250);

    $parte = $this->service->registrarPorHorometro($asignacion, lecturaFinal: '254');

    expect($parte->lectura_inicial)->toBe('250.00')
        ->and($parte->horas)->toBe('4.00');
});

test('las horas que exceden la jornada se marcan como extra y exigen motivo', function (): void {
    $asignacion = asignacionConTarifa($this->asignar, $this->obra, tarifa: 1000, jornada: 8, horometro: 100);

    // 100 → 110 = 10h, jornada 8 → 2h extra. Sin motivo debe fallar.
    expect(fn () => $this->service->registrarPorHorometro($asignacion, lecturaFinal: '110'))
        ->toThrow(ParteInvalidoException::class);

    // Con motivo, pasa y registra las 2h extra.
    $parte = $this->service->registrarPorHorometro($asignacion->fresh(), lecturaFinal: '110', motivoHorasExtra: 'TERMINAR FUNDICIÓN');

    expect($parte->horas)->toBe('10.00')
        ->and($parte->horas_extra)->toBe('2.00')
        ->and($parte->costo_cache)->toBe('10000.00');
});

test('el horómetro no puede retroceder', function (): void {
    $asignacion = asignacionConTarifa($this->asignar, $this->obra, tarifa: 1000, horometro: 500);

    // Lectura final 480 < horómetro actual 500.
    expect(fn () => $this->service->registrarPorHorometro($asignacion, lecturaFinal: '480', lecturaInicial: '480'))
        ->toThrow(ParteInvalidoException::class);
});

test('parte manual registra horas directas sin tocar el horómetro', function (): void {
    $asignacion = asignacionConTarifa($this->asignar, $this->obra, tarifa: 1200, jornada: 8, horometro: 300);

    $parte = $this->service->registrarManual($asignacion, horas: '6');

    expect($parte->metodo_captura)->toBe(MetodoCapturaHoras::Manual)
        ->and($parte->lectura_inicial)->toBeNull()
        ->and($parte->horas)->toBe('6.00')
        ->and($parte->costo_cache)->toBe('7200.00')
        // El horómetro de la máquina NO cambió.
        ->and($parte->asignacion->maquina->fresh()->horometro_actual)->toBe('300.00');
});

test('no se puede registrar un parte de horas cero o negativas', function (): void {
    $asignacion = asignacionConTarifa($this->asignar, $this->obra, tarifa: 1000);

    expect(fn () => $this->service->registrarManual($asignacion, horas: '0'))
        ->toThrow(ParteInvalidoException::class);
});

test('no se pueden registrar partes en una asignación finalizada', function (): void {
    $asignacion = asignacionConTarifa($this->asignar, $this->obra, tarifa: 1000);
    $this->asignar->finalizar($asignacion);

    expect(fn () => $this->service->registrarManual($asignacion->fresh(), horas: '5'))
        ->toThrow(ParteInvalidoException::class);
});

test('el cobro de una obra es la suma de los costos de sus partes', function (): void {
    $asignacion = asignacionConTarifa($this->asignar, $this->obra, tarifa: 1000, jornada: 8, horometro: 0);

    $this->service->registrarManual($asignacion, horas: '5'); // 5,000
    $this->service->registrarManual($asignacion, horas: '3'); // 3,000

    $suma = ParteTrabajo::query()
        ->where('asignacion_maquina_id', $asignacion->id)
        ->sum('costo_cache');

    expect((float) $suma)->toBe(8000.0);
});
