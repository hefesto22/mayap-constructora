<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
});

/**
 * @param array<string, mixed> $overrides
 */
function proyectoConFechas(array $overrides): Proyecto
{
    return Proyecto::factory()
        ->enZona(test()->zona)
        ->paraCliente(test()->cliente)
        ->enEjecucion()
        ->create($overrides);
}

test('diasTranscurridos cuenta desde la fecha de inicio', function (): void {
    $proyecto = proyectoConFechas([
        'fecha_inicio'       => Carbon::today()->subDays(10),
        'fecha_fin_estimada' => Carbon::today()->addDays(20),
        'plazo_dias'         => 30,
    ]);

    expect($proyecto->diasTranscurridos())->toBe(10);
});

test('diasRestantes da los días hasta la fecha fin estimada', function (): void {
    $proyecto = proyectoConFechas([
        'fecha_inicio'       => Carbon::today(),
        'fecha_fin_estimada' => Carbon::today()->addDays(30),
        'plazo_dias'         => 30,
    ]);

    expect($proyecto->diasRestantes())->toBe(30);
});

test('porcentajeTiempo refleja el avance del plazo', function (): void {
    $proyecto = proyectoConFechas([
        'fecha_inicio'       => Carbon::today()->subDays(15),
        'fecha_fin_estimada' => Carbon::today()->addDays(15),
        'plazo_dias'         => 30,
    ]);

    expect($proyecto->porcentajeTiempo())->toBe(50.0);
});

test('estaAtrasado es verdadero si pasó el fin estimado y el avance no llegó al 100%', function (): void {
    $atrasado = proyectoConFechas([
        'fecha_inicio'        => Carbon::today()->subDays(40),
        'fecha_fin_estimada'  => Carbon::today()->subDays(10),
        'plazo_dias'          => 30,
        'avance_fisico_cache' => 0,
    ]);

    $aTiempo = proyectoConFechas([
        'fecha_inicio'       => Carbon::today(),
        'fecha_fin_estimada' => Carbon::today()->addDays(30),
        'plazo_dias'         => 30,
    ]);

    expect($atrasado->estaAtrasado())->toBeTrue();
    expect($aTiempo->estaAtrasado())->toBeFalse();
});

test('helpers de ejecución son null cuando la obra no ha arrancado', function (): void {
    $borrador = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    expect($borrador->diasTranscurridos())->toBeNull();
    expect($borrador->diasRestantes())->toBeNull();
    expect($borrador->porcentajeTiempo())->toBeNull();
    expect($borrador->estaAtrasado())->toBeFalse();
});
