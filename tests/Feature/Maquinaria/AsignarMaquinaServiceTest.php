<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\AsignacionInvalidaException;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Maquinaria\AsignarMaquinaService;

/*
|--------------------------------------------------------------------------
| Golden tests del ciclo de asignación de máquina a obra.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = new AsignarMaquinaService;
    $this->proyecto = Proyecto::factory()->create();
});

test('asignar una máquina disponible la deja Asignada y hereda su tarifa', function (): void {
    $maquina = Maquina::factory()->create(['tarifa_hora' => 1800, 'estado' => EstadoMaquina::Disponible->value]);

    $asignacion = $this->service->asignar($maquina, $this->proyecto->id);

    expect($asignacion->codigo)->toStartWith('ASMQ-')
        ->and($asignacion->estado)->toBe(EstadoAsignacion::Activa)
        ->and($asignacion->tarifa_hora_pactada)->toBe('1800.00')
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Asignada);
});

test('se puede pactar una tarifa distinta a la de la máquina para la obra', function (): void {
    $maquina = Maquina::factory()->create(['tarifa_hora' => 1800]);

    $asignacion = $this->service->asignar($maquina, $this->proyecto->id, tarifaPactada: '2200');

    expect($asignacion->tarifa_hora_pactada)->toBe('2200.00');
});

test('no se puede asignar una máquina que no está disponible', function (): void {
    $maquina = Maquina::factory()->asignada()->create();

    expect(fn () => $this->service->asignar($maquina, $this->proyecto->id))
        ->toThrow(AsignacionInvalidaException::class);

    expect(AsignacionMaquina::query()->count())->toBe(0);
});

test('una máquina no puede tener dos asignaciones activas a la vez', function (): void {
    $maquina = Maquina::factory()->create();
    $otraObra = Proyecto::factory()->create();

    $this->service->asignar($maquina, $this->proyecto->id);

    // Tras la primera, la máquina quedó Asignada → la segunda debe fallar.
    expect(fn () => $this->service->asignar($maquina->fresh(), $otraObra->id))
        ->toThrow(AsignacionInvalidaException::class);

    expect(AsignacionMaquina::query()->activas()->count())->toBe(1);
});

test('finalizar una asignación la cierra y libera la máquina', function (): void {
    $maquina = Maquina::factory()->create();
    $asignacion = $this->service->asignar($maquina, $this->proyecto->id);

    $this->service->finalizar($asignacion);

    $asignacion->refresh();
    expect($asignacion->estado)->toBe(EstadoAsignacion::Finalizada)
        ->and($asignacion->fecha_fin)->not->toBeNull()
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Disponible);
});

test('finalizar una asignación ya finalizada es rechazado', function (): void {
    $maquina = Maquina::factory()->create();
    $asignacion = $this->service->asignar($maquina, $this->proyecto->id);
    $this->service->finalizar($asignacion);

    expect(fn () => $this->service->finalizar($asignacion->fresh()))
        ->toThrow(AsignacionInvalidaException::class);
});

test('tras finalizar, la máquina se puede reasignar a otra obra', function (): void {
    $maquina = Maquina::factory()->create();
    $otraObra = Proyecto::factory()->create();

    $primera = $this->service->asignar($maquina, $this->proyecto->id);
    $this->service->finalizar($primera);

    $segunda = $this->service->asignar($maquina->fresh(), $otraObra->id);

    expect($segunda->estado)->toBe(EstadoAsignacion::Activa)
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Asignada)
        ->and(AsignacionMaquina::query()->count())->toBe(2);
});
