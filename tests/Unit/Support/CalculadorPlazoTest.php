<?php

declare(strict_types=1);

use App\Enums\ModoPlazo;
use App\Support\CalculadorPlazo;
use Illuminate\Support\Carbon;

// 2026-06-01 es lunes (verificado): base estable para los cálculos.

test('modo calendario suma días corridos', function (): void {
    $inicio = Carbon::parse('2026-06-01'); // lunes

    $fin = CalculadorPlazo::calcularFechaFin($inicio, 5, ModoPlazo::Calendario);

    expect($fin->toDateString())->toBe('2026-06-06'); // sábado
});

test('modo hábiles salta fines de semana', function (): void {
    $inicio = Carbon::parse('2026-06-01'); // lunes

    // 5 hábiles desde lunes: mar, mié, jue, vie, (sáb/dom saltan), lun.
    $fin = CalculadorPlazo::calcularFechaFin($inicio, 5, ModoPlazo::Habiles);

    expect($fin->toDateString())->toBe('2026-06-08'); // lunes siguiente
});

test('modo hábiles desde viernes cae el lunes siguiente', function (): void {
    $inicio = Carbon::parse('2026-06-05'); // viernes

    $fin = CalculadorPlazo::calcularFechaFin($inicio, 1, ModoPlazo::Habiles);

    expect($fin->toDateString())->toBe('2026-06-08'); // lunes
});

test('plazo menor a 1 lanza excepción', function (): void {
    expect(fn () => CalculadorPlazo::calcularFechaFin(Carbon::parse('2026-06-01'), 0, ModoPlazo::Calendario))
        ->toThrow(InvalidArgumentException::class);
});

test('no muta la fecha de inicio recibida', function (): void {
    $inicio = Carbon::parse('2026-06-01');

    CalculadorPlazo::calcularFechaFin($inicio, 10, ModoPlazo::Calendario);

    expect($inicio->toDateString())->toBe('2026-06-01');
});
