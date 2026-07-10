<?php

declare(strict_types=1);

use App\Enums\ModoPlazo;
use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use App\Services\Proyectos\AjustarPlazoProyectoService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->service = new AjustarPlazoProyectoService;
});

test('ajusta fecha de inicio y plazo, recalculando el fin (calendario)', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->enEjecucion()->create();

    $resultado = $this->service->ejecutar($proyecto, Carbon::parse('2026-06-01'), 10, ModoPlazo::Calendario);

    expect($resultado->fecha_inicio->toDateString())->toBe('2026-06-01');
    expect($resultado->plazo_dias)->toBe(10);
    expect($resultado->fecha_fin_estimada->toDateString())->toBe('2026-06-11');
});

test('ajusta en modo hábiles saltando fines de semana', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->pausada()->create();

    $resultado = $this->service->ejecutar($proyecto, Carbon::parse('2026-06-01'), 5, ModoPlazo::Habiles);

    expect($resultado->fecha_fin_estimada->toDateString())->toBe('2026-06-08');
});

test('rechaza ajustar si el proyecto no está en ejecución', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->aprobada()->create();

    expect(fn () => $this->service->ejecutar($proyecto, Carbon::today(), 10, ModoPlazo::Calendario))
        ->toThrow(DatosEjecucionInvalidosException::class);
});

test('rechaza plazo menor a 1', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->enEjecucion()->create();

    expect(fn () => $this->service->ejecutar($proyecto, Carbon::today(), 0, ModoPlazo::Calendario))
        ->toThrow(DatosEjecucionInvalidosException::class);
});
