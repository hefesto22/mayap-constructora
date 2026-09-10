<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\AsignacionInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Maquinaria\AgendarMaquinaService;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Proyectos\CambiarEstadoEjecucionService;

/*
|--------------------------------------------------------------------------
| La máquina siempre tiene puerta de vuelta al parque (2026-08-16).
|--------------------------------------------------------------------------
| Antes: al cerrar una obra la máquina quedaba "Asignada" para siempre, y
| el catálogo de Maquinaria no ofrecía ninguna acción para liberarla.
*/

beforeEach(function (): void {
    $this->asignar = app(AsignarMaquinaService::class);
    $this->estados = app(CambiarEstadoEjecucionService::class);
});

test('GOLDEN: finalizar la obra libera sus máquinas y cancela sus agendados', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();

    app(AgendarMaquinaService::class)->agendar(
        $maquina->id,
        $obra->id,
        today()->addDays(3)->toDateString(),
        horaEntrada: '08:00:00',
    );

    $this->asignar->asignar($maquina, $obra->id);
    expect($maquina->fresh()->estado)->toBe(EstadoMaquina::Asignada);

    $this->estados->finalizar($obra);

    expect($maquina->fresh()->estado)->toBe(EstadoMaquina::Disponible)
        ->and(AsignacionMaquina::query()
            ->where('proyecto_id', $obra->id)
            ->where('estado', EstadoAsignacion::Activa->value)
            ->count())->toBe(0)
        ->and(AgendaMaquina::query()->where('proyecto_id', $obra->id)->count())->toBe(0);
});

test('cancelar la obra también suelta la maquinaria', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();

    $this->asignar->asignar($maquina, $obra->id);

    $this->estados->cancelar($obra, 'EL CLIENTE SE ECHÓ PARA ATRÁS');

    expect($maquina->fresh()->estado)->toBe(EstadoMaquina::Disponible);
});

test('el agendado con llegada CONFIRMADA sobrevive al cierre: es historia, no plan', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();

    $agendado = app(AgendarMaquinaService::class)->agendar(
        $maquina->id,
        $obra->id,
        today()->toDateString(),
        horaEntrada: '08:00:00',
    );

    $agendado->update(['llegada_confirmada_at' => now()]);

    $this->estados->finalizar($obra);

    expect(AgendaMaquina::query()->whereKey($agendado->id)->exists())->toBeTrue();
});

test('liberar de la obra cierra la asignación y devuelve la máquina al parque', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create();

    $asignacion = $this->asignar->asignar($maquina, $obra->id);

    $cerrada = $this->asignar->liberarDeObra($maquina->fresh());

    expect($cerrada?->id)->toBe($asignacion->id)
        ->and($cerrada?->estado)->toBe(EstadoAsignacion::Finalizada)
        ->and($cerrada?->fecha_fin)->not->toBeNull()
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Disponible);
});

test('liberar de la obra destraba una máquina Asignada sin asignación abierta', function (): void {
    // Estado huérfano: antes no había ninguna pantalla que la sacara.
    $maquina = Maquina::factory()->create(['estado' => EstadoMaquina::Asignada->value]);

    $cerrada = $this->asignar->liberarDeObra($maquina);

    expect($cerrada)->toBeNull()
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Disponible);
});

test('liberar de la obra rechaza una máquina que no está asignada', function (): void {
    $maquina = Maquina::factory()->create(['estado' => EstadoMaquina::Disponible->value]);

    expect(fn () => $this->asignar->liberarDeObra($maquina))
        ->toThrow(AsignacionInvalidaException::class);
});
