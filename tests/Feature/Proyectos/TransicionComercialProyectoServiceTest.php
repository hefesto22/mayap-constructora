<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Exceptions\Proyectos\TransicionEstadoInvalidaException;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use App\Services\Proyectos\TransicionComercialProyectoService;

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->service = new TransicionComercialProyectoService;
});

test('cambia de borrador a enviada', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->create();

    $resultado = $this->service->cambiar($proyecto, EstadoProyecto::Enviada);

    expect($resultado->estado)->toBe(EstadoProyecto::Enviada);
});

test('cambia de enviada a aprobada', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->enviada()->create();

    $resultado = $this->service->cambiar($proyecto, EstadoProyecto::Aprobada);

    expect($resultado->estado)->toBe(EstadoProyecto::Aprobada);
});

test('volver a borrador desde enviada', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->enviada()->create();

    $resultado = $this->service->volverABorrador($proyecto, 'ERROR EN UNA FICHA');

    expect($resultado->estado)->toBe(EstadoProyecto::Borrador);
});

test('rechaza una transición inválida', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->create(); // borrador

    expect(fn () => $this->service->cambiar($proyecto, EstadoProyecto::Aprobada))
        ->toThrow(TransicionEstadoInvalidaException::class);
});
