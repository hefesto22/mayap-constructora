<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Exceptions\Proyectos\TransicionEstadoInvalidaException;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use App\Services\Proyectos\CambiarEstadoEjecucionService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->service = new CambiarEstadoEjecucionService;
});

function proyectoEnEjecucion(): Proyecto
{
    return Proyecto::factory()
        ->enZona(test()->zona)
        ->paraCliente(test()->cliente)
        ->enEjecucion()
        ->create();
}

test('pausar mueve a Pausada y guarda el motivo en mayúsculas', function (): void {
    $proyecto = proyectoEnEjecucion();

    $resultado = $this->service->pausar($proyecto, 'lluvias intensas');

    expect($resultado->estado)->toBe(EstadoProyecto::Pausada);
    expect($resultado->motivo_pausa)->toBe('LLUVIAS INTENSAS');
});

test('pausar sin motivo lanza excepción', function (): void {
    $proyecto = proyectoEnEjecucion();

    expect(fn () => $this->service->pausar($proyecto, '   '))
        ->toThrow(DatosEjecucionInvalidosException::class);
});

test('reactivar vuelve a En ejecución y limpia el motivo de pausa', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->pausada('FALTA DE MATERIAL')
        ->create();

    $resultado = $this->service->reactivar($proyecto);

    expect($resultado->estado)->toBe(EstadoProyecto::EnEjecucion);
    expect($resultado->motivo_pausa)->toBeNull();
});

test('finalizar fija la fecha de fin real', function (): void {
    $proyecto = proyectoEnEjecucion();

    $resultado = $this->service->finalizar($proyecto, Carbon::today());

    expect($resultado->estado)->toBe(EstadoProyecto::Finalizada);
    expect($resultado->fecha_fin_real->toDateString())->toBe(Carbon::today()->toDateString());
});

test('finalizar también funciona desde Pausada', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->pausada()
        ->create();

    $resultado = $this->service->finalizar($proyecto);

    expect($resultado->estado)->toBe(EstadoProyecto::Finalizada);
});

test('finalizar con fecha anterior al inicio la recorta al inicio (no viola CHECK)', function (): void {
    $proyecto = proyectoEnEjecucion(); // fecha_inicio = hoy

    $resultado = $this->service->finalizar($proyecto, Carbon::yesterday());

    expect($resultado->fecha_fin_real->toDateString())
        ->toBe($proyecto->fecha_inicio->toDateString());
});

test('cancelar requiere motivo y lo guarda', function (): void {
    $proyecto = proyectoEnEjecucion();

    $resultado = $this->service->cancelar($proyecto, 'cliente desistió');

    expect($resultado->estado)->toBe(EstadoProyecto::Cancelada);
    expect($resultado->motivo_cancelacion)->toBe('CLIENTE DESISTIÓ');
    expect($resultado->fecha_fin_real)->not->toBeNull();
});

test('cancelar sin motivo lanza excepción', function (): void {
    $proyecto = proyectoEnEjecucion();

    expect(fn () => $this->service->cancelar($proyecto, ''))
        ->toThrow(DatosEjecucionInvalidosException::class);
});

test('una transición inválida (finalizar un borrador) lanza excepción', function (): void {
    $borrador = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    expect(fn () => $this->service->finalizar($borrador))
        ->toThrow(TransicionEstadoInvalidaException::class);
});
