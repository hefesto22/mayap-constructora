<?php

declare(strict_types=1);

use App\Enums\ModalidadTrabajo;
use App\Enums\UnidadRenta;
use App\Models\AsignacionMaquina;
use App\Models\Cliente;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use App\Services\Maquinaria\RegistrarParteService;
use App\Services\Proyectos\AprobarRentaService;
use App\Services\Proyectos\FinalizarRentaService;

/*
|--------------------------------------------------------------------------
| Renta por VIAJE y por KILÓMETRO (decisión Mauricio 2026-07-20): las
| volquetas se cotizan por viajes y los pick-ups por km. El cierre
| compara lo pactado contra lo real de los partes EN SU DIMENSIÓN:
| viajes vs viajes, km vs km — el mínimo sigue siendo lo cotizado.
|--------------------------------------------------------------------------
*/

test('la tarifa sugerida por viaje y por km sale del catálogo de la máquina', function (): void {
    $volqueta = Maquina::factory()->create([
        'tarifa_viaje' => 1200,
        'tarifa_km'    => 35,
    ]);

    expect(UnidadRenta::Viaje->tarifaSugerida($volqueta))->toBe('1200.00')
        ->and(UnidadRenta::Kilometro->tarifaSugerida($volqueta))->toBe('35.00')
        ->and(UnidadRenta::Viaje->dimension())->toBe('viajes')
        ->and(UnidadRenta::Kilometro->dimension())->toBe('km')
        ->and(UnidadRenta::Dia->dimension())->toBe('horas');
});

test('al finalizar, los viajes de más se cobran como extra a la tarifa por viaje', function (): void {
    $volqueta = Maquina::factory()->create(['modalidad_trabajo' => 'viajes']);

    // Renta SIN ISV para que el extra sea directo: 2 viajes × L 1,000.
    $proyecto = Proyecto::factory()
        ->renta()
        ->for(Cliente::factory()->create())
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);

    ProyectoLineaRenta::factory()->create([
        'proyecto_id'     => $proyecto->id,
        'maquina_id'      => $volqueta->id,
        'unidad'          => UnidadRenta::Viaje,
        'cantidad'        => '6.00',
        'tarifa_snapshot' => '1000.00',
        'subtotal_cache'  => '6000.00',
    ]);

    // Aprobar genera la CxC (requisito del cierre) y agenda.
    app(AprobarRentaService::class)->aprobar($proyecto);

    // Partes reales: 8 viajes (6 pactados) — 2 de extra.
    $asignacion = AsignacionMaquina::factory()->create([
        'proyecto_id' => $proyecto->id,
        'maquina_id'  => $volqueta->id,
    ]);

    app(RegistrarParteService::class)->registrarManual(
        asignacion: $asignacion,
        horas: '8.00',
        modalidad: ModalidadTrabajo::Viajes,
        viajes: 8,
        viajeOrigen: 'BANCO DE ARENA',
        viajeDestino: 'OBRA LAS FLORES',
    );

    $resultado = app(FinalizarRentaService::class)->finalizar($proyecto->refresh());

    // Extra = 2 viajes × L 1,000 (sin ISV).
    expect($resultado['extra'])->toBe('2000.00');

    $filaViajes = collect($resultado['detalle'])->firstWhere('unidad', 'Viajes');

    expect($filaViajes)->not->toBeNull()
        ->and($filaViajes['pactadas'])->toBe('6.00')
        ->and($filaViajes['reales'])->toBe('8.00');
});

test('hacer menos viajes que lo pactado no descuenta nada (el mínimo es lo cotizado)', function (): void {
    $volqueta = Maquina::factory()->create(['modalidad_trabajo' => 'viajes']);

    $proyecto = Proyecto::factory()
        ->renta()
        ->for(Cliente::factory()->create())
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);

    ProyectoLineaRenta::factory()->create([
        'proyecto_id'     => $proyecto->id,
        'maquina_id'      => $volqueta->id,
        'unidad'          => UnidadRenta::Viaje,
        'cantidad'        => '6.00',
        'tarifa_snapshot' => '1000.00',
        'subtotal_cache'  => '6000.00',
    ]);

    app(AprobarRentaService::class)->aprobar($proyecto);

    $asignacion = AsignacionMaquina::factory()->create([
        'proyecto_id' => $proyecto->id,
        'maquina_id'  => $volqueta->id,
    ]);

    app(RegistrarParteService::class)->registrarManual(
        asignacion: $asignacion,
        horas: '5.00',
        modalidad: ModalidadTrabajo::Viajes,
        viajes: 4,
    );

    $resultado = app(FinalizarRentaService::class)->finalizar($proyecto->refresh());

    expect($resultado['extra'])->toBe('0.00');
});
