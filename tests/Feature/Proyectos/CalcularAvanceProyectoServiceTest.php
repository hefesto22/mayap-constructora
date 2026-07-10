<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\ProyectoActividad;
use App\Models\Zona;
use App\Services\Proyectos\CalcularAvanceProyectoService;

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->service = new CalcularAvanceProyectoService;
    $this->proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();
});

test('sin actividades el avance es 0', function (): void {
    expect($this->service->calcular($this->proyecto))->toBe('0.00');
});

test('peso uniforme: 1 de 4 completadas = 25%', function (): void {
    ProyectoActividad::factory()->paraProyecto($this->proyecto)->completada()->create();
    ProyectoActividad::factory()->paraProyecto($this->proyecto)->count(3)->create();

    expect($this->service->calcular($this->proyecto))->toBe('25.00');
});

test('el hook actualiza avance_fisico_cache del proyecto automáticamente', function (): void {
    ProyectoActividad::factory()->paraProyecto($this->proyecto)->completada()->create();
    ProyectoActividad::factory()->paraProyecto($this->proyecto)->count(3)->create();

    expect($this->proyecto->refresh()->avance_fisico_cache)->toBe('25.00');
});

test('ponderado: completar la actividad de peso 30 de 50 total = 60%', function (): void {
    ProyectoActividad::factory()->paraProyecto($this->proyecto)->conPeso('30')->completada()->create();
    ProyectoActividad::factory()->paraProyecto($this->proyecto)->conPeso('20')->create();

    expect($this->service->calcular($this->proyecto))->toBe('60.00');
});

test('completar todas las actividades da 100%', function (): void {
    ProyectoActividad::factory()->paraProyecto($this->proyecto)->completada()->count(3)->create();

    expect($this->service->calcular($this->proyecto))->toBe('100.00');
    expect($this->proyecto->refresh()->avance_fisico_cache)->toBe('100.00');
});

test('al eliminar una actividad se recalcula el avance', function (): void {
    $completada = ProyectoActividad::factory()->paraProyecto($this->proyecto)->completada()->create();
    ProyectoActividad::factory()->paraProyecto($this->proyecto)->create();

    expect($this->proyecto->refresh()->avance_fisico_cache)->toBe('50.00');

    $completada->delete();

    expect($this->proyecto->refresh()->avance_fisico_cache)->toBe('0.00');
});
