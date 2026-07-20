<?php

declare(strict_types=1);

use App\Enums\ModalidadTrabajo;
use App\Exceptions\Maquinaria\ParteInvalidoException;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Services\Maquinaria\RegistrarParteService;

/*
|--------------------------------------------------------------------------
| Modalidades de trabajo del parte (decisión Mauricio 2026-07-20 — "así
| funcionan"): pesada por horómetro, pick-ups por km, volquetas por
| viajes origen → destino, camiones por flete. Las horas del día siguen
| siendo el costo interno.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(RegistrarParteService::class);
});

function asignacionActivaDeMaquina(?Maquina $maquina = null): AsignacionMaquina
{
    return AsignacionMaquina::factory()->create([
        'maquina_id' => ($maquina ?? Maquina::factory()->create())->id,
    ]);
}

test('el parte por kilometraje guarda los km y los suma al kilometraje de la máquina', function (): void {
    $maquina = Maquina::factory()->create([
        'modalidad_trabajo'  => 'kilometraje',
        'kilometraje_actual' => 1500,
    ]);

    $parte = $this->service->registrarManual(
        asignacion: asignacionActivaDeMaquina($maquina),
        horas: '6.00',
        modalidad: ModalidadTrabajo::Kilometraje,
        kmRecorridos: '120.50',
    );

    expect((string) $parte->refresh()->km_recorridos)->toBe('120.50')
        ->and($parte->modalidad)->toBe(ModalidadTrabajo::Kilometraje)
        ->and((string) $maquina->refresh()->kilometraje_actual)->toBe('1620.50');
});

test('el parte por kilometraje sin km revienta', function (): void {
    expect(fn () => $this->service->registrarManual(
        asignacion: asignacionActivaDeMaquina(),
        horas: '6.00',
        modalidad: ModalidadTrabajo::Kilometraje,
    ))->toThrow(ParteInvalidoException::class, 'kilómetros');
});

test('el parte por viajes guarda viajes, origen, destino y material', function (): void {
    $parte = $this->service->registrarManual(
        asignacion: asignacionActivaDeMaquina(),
        horas: '8.00',
        modalidad: ModalidadTrabajo::Viajes,
        viajes: 6,
        viajeOrigen: 'BANCO DE ARENA',
        viajeDestino: 'OBRA LAS FLORES',
        viajeMaterial: 'MATERIAL SELECTO',
    );

    $parte->refresh();

    expect($parte->viajes)->toBe(6)
        ->and($parte->viaje_origen)->toBe('BANCO DE ARENA')
        ->and($parte->viaje_destino)->toBe('OBRA LAS FLORES')
        ->and($parte->viaje_material)->toBe('MATERIAL SELECTO');
});

test('el parte por viajes sin número de viajes revienta', function (): void {
    expect(fn () => $this->service->registrarManual(
        asignacion: asignacionActivaDeMaquina(),
        horas: '8.00',
        modalidad: ModalidadTrabajo::Viajes,
        viajeOrigen: 'BANCO DE ARENA',
    ))->toThrow(ParteInvalidoException::class, 'viajes');
});

test('el parte de flete exige la actividad', function (): void {
    expect(fn () => $this->service->registrarManual(
        asignacion: asignacionActivaDeMaquina(),
        horas: '4.00',
        modalidad: ModalidadTrabajo::Flete,
    ))->toThrow(ParteInvalidoException::class, 'actividad');
});

test('el parte de flete guarda la actividad y el costo sigue siendo horas × tarifa', function (): void {
    $asignacion = asignacionActivaDeMaquina();

    $parte = $this->service->registrarManual(
        asignacion: $asignacion,
        horas: '4.00',
        modalidad: ModalidadTrabajo::Flete,
        actividad: 'FLETE DE CEMENTO A LA OBRA X',
    );

    $parte->refresh();

    $esperado = bcmul('4.00', (string) $asignacion->tarifa_hora_pactada, 2);

    expect($parte->actividad)->toBe('FLETE DE CEMENTO A LA OBRA X')
        ->and(bccomp((string) $parte->costo_cache, $esperado, 2))->toBe(0);
});

test('un parte normal sigue naciendo en modalidad horas', function (): void {
    $parte = $this->service->registrarManual(
        asignacion: asignacionActivaDeMaquina(),
        horas: '5.00',
    );

    expect($parte->refresh()->modalidad)->toBe(ModalidadTrabajo::Horas)
        ->and($parte->km_recorridos)->toBeNull()
        ->and($parte->viajes)->toBeNull();
});
