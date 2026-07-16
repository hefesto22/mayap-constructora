<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\MantenimientoService;

/*
|--------------------------------------------------------------------------
| Golden tests de mantenimiento y sustitución por avería.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->asignar = new AsignarMaquinaService;
    // SIEMPRE app(): el constructor del service crece con el proyecto.
    $this->service = app(MantenimientoService::class);
    $this->obra = Proyecto::factory()->create();
});

test('enviar a mantenimiento una máquina disponible la deja fuera de servicio', function (): void {
    $maquina = Maquina::factory()->create(['estado' => EstadoMaquina::Disponible->value]);

    $mantenimiento = $this->service->enviarAMantenimiento($maquina, motivo: 'FALLA DE MOTOR');

    expect($mantenimiento->codigo)->toStartWith('MANT-')
        ->and($mantenimiento->estado)->toBe(EstadoMantenimiento::EnProceso)
        ->and($mantenimiento->asignacion_finalizada_id)->toBeNull()
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Mantenimiento);
});

test('GOLDEN: avería con sustitución finaliza la asignación y asigna la sustituta a la misma obra', function (): void {
    $averiada = Maquina::factory()->create();
    $sustituta = Maquina::factory()->create(['tarifa_hora' => 1700, 'estado' => EstadoMaquina::Disponible->value]);

    $asignacion = $this->asignar->asignar($averiada, $this->obra->id, tarifaPactada: '1500');

    $mantenimiento = $this->service->enviarAMantenimiento(
        maquina: $averiada,
        motivo: 'RUPTURA DE MANGUERA HIDRÁULICA',
        sustituta: $sustituta,
    );

    // La máquina averiada queda en mantenimiento y su asignación finalizada.
    expect($averiada->fresh()->estado)->toBe(EstadoMaquina::Mantenimiento)
        ->and($asignacion->fresh()->estado)->toBe(EstadoAsignacion::Finalizada)
        ->and($mantenimiento->asignacion_finalizada_id)->toBe($asignacion->id);

    // La sustituta quedó asignada a la MISMA obra y trazada en el mantenimiento.
    $asignacionSustituta = AsignacionMaquina::query()->findOrFail($mantenimiento->asignacion_sustituta_id);

    expect($sustituta->fresh()->estado)->toBe(EstadoMaquina::Asignada)
        ->and($asignacionSustituta->proyecto_id)->toBe($this->obra->id)
        ->and($asignacionSustituta->estado)->toBe(EstadoAsignacion::Activa);
});

test('no se puede sustituir si la máquina averiada no tenía asignación activa', function (): void {
    $averiada = Maquina::factory()->create(['estado' => EstadoMaquina::Disponible->value]);
    $sustituta = Maquina::factory()->create();

    expect(fn () => $this->service->enviarAMantenimiento($averiada, motivo: 'X', sustituta: $sustituta))
        ->toThrow(MantenimientoInvalidoException::class);

    // Nada cambió: la avería no se concretó.
    expect($averiada->fresh()->estado)->toBe(EstadoMaquina::Disponible)
        ->and($sustituta->fresh()->estado)->toBe(EstadoMaquina::Disponible);
});

test('no se puede enviar a mantenimiento una máquina ya en mantenimiento o de baja', function (): void {
    $enMantenimiento = Maquina::factory()->enMantenimiento()->create();
    $deBaja = Maquina::factory()->deBaja()->create();

    expect(fn () => $this->service->enviarAMantenimiento($enMantenimiento, motivo: 'X'))
        ->toThrow(MantenimientoInvalidoException::class);

    expect(fn () => $this->service->enviarAMantenimiento($deBaja, motivo: 'X'))
        ->toThrow(MantenimientoInvalidoException::class);
});

test('finalizar el mantenimiento devuelve la máquina a disponible', function (): void {
    $maquina = Maquina::factory()->create();
    $mantenimiento = $this->service->enviarAMantenimiento($maquina, motivo: 'REVISIÓN GENERAL');

    $this->service->finalizar($mantenimiento);

    $mantenimiento->refresh();
    expect($mantenimiento->estado)->toBe(EstadoMantenimiento::Finalizado)
        ->and($mantenimiento->fecha_fin)->not->toBeNull()
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Disponible);
});

test('finalizar un mantenimiento ya finalizado es rechazado', function (): void {
    $maquina = Maquina::factory()->create();
    $mantenimiento = $this->service->enviarAMantenimiento($maquina, motivo: 'X');
    $this->service->finalizar($mantenimiento);

    expect(fn () => $this->service->finalizar($mantenimiento->fresh()))
        ->toThrow(MantenimientoInvalidoException::class);
});

test('tras reparar, la máquina se puede reasignar a una obra', function (): void {
    $maquina = Maquina::factory()->create();
    $mantenimiento = $this->service->enviarAMantenimiento($maquina, motivo: 'X');
    $this->service->finalizar($mantenimiento);

    $asignacion = $this->asignar->asignar($maquina->fresh(), $this->obra->id);

    expect($asignacion->estado)->toBe(EstadoAsignacion::Activa)
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Asignada);
});
