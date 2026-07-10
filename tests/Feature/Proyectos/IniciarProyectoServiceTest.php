<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Enums\ModoPlazo;
use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Exceptions\Proyectos\TransicionEstadoInvalidaException;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use App\Services\Proyectos\IniciarProyectoService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->service = new IniciarProyectoService;
});

function proyectoAprobado(): Proyecto
{
    return Proyecto::factory()
        ->enZona(test()->zona)
        ->paraCliente(test()->cliente)
        ->aprobada()
        ->create();
}

test('inicia un proyecto aprobado y calcula la fecha de fin (calendario)', function (): void {
    $proyecto = proyectoAprobado();
    $inicio = Carbon::parse('2026-06-01');

    $resultado = $this->service->ejecutar($proyecto, $inicio, 30, ModoPlazo::Calendario);

    expect($resultado->estado)->toBe(EstadoProyecto::EnEjecucion);
    expect($resultado->fecha_inicio->toDateString())->toBe('2026-06-01');
    expect($resultado->plazo_dias)->toBe(30);
    expect($resultado->modo_plazo)->toBe(ModoPlazo::Calendario);
    expect($resultado->fecha_fin_estimada->toDateString())->toBe('2026-07-01');
});

test('modo hábiles calcula fin saltando fines de semana', function (): void {
    $proyecto = proyectoAprobado();
    $inicio = Carbon::parse('2026-06-01'); // lunes

    $resultado = $this->service->ejecutar($proyecto, $inicio, 5, ModoPlazo::Habiles);

    expect($resultado->fecha_fin_estimada->toDateString())->toBe('2026-06-08');
});

test('no inicia un proyecto que no está aprobado', function (): void {
    $borrador = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(); // borrador

    expect(fn () => $this->service->ejecutar($borrador, Carbon::today(), 30, ModoPlazo::Calendario))
        ->toThrow(TransicionEstadoInvalidaException::class);
});

test('rechaza plazo menor a 1 día', function (): void {
    $proyecto = proyectoAprobado();

    expect(fn () => $this->service->ejecutar($proyecto, Carbon::today(), 0, ModoPlazo::Calendario))
        ->toThrow(DatosEjecucionInvalidosException::class);
});
