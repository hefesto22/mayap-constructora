<?php

declare(strict_types=1);

use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use App\Services\Proyectos\RegistrarAnticipoService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->service = new RegistrarAnticipoService;
});

test('registra anticipo en un proyecto aprobado', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->aprobada()
        ->create();

    $resultado = $this->service->ejecutar($proyecto, 25000.50, Carbon::parse('2026-06-10'));

    expect($resultado->anticipo_monto)->toBe('25000.50');
    expect($resultado->anticipo_recibido)->toBeTrue();
    expect($resultado->anticipo_fecha->toDateString())->toBe('2026-06-10');
});

test('registra anticipo durante la ejecución', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enEjecucion()
        ->create();

    $resultado = $this->service->ejecutar($proyecto, 10000);

    expect($resultado->anticipo_recibido)->toBeTrue();
    expect($resultado->anticipo_monto)->toBe('10000.00');
});

test('rechaza monto cero o negativo', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->aprobada()
        ->create();

    expect(fn () => $this->service->ejecutar($proyecto, 0))
        ->toThrow(DatosEjecucionInvalidosException::class);

    expect(fn () => $this->service->ejecutar($proyecto, -500))
        ->toThrow(DatosEjecucionInvalidosException::class);
});

test('rechaza anticipo en estado borrador', function (): void {
    $borrador = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    expect(fn () => $this->service->ejecutar($borrador, 5000))
        ->toThrow(DatosEjecucionInvalidosException::class);
});
