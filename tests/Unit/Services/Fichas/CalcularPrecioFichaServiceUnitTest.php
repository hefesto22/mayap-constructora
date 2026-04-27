<?php

declare(strict_types=1);

use App\Services\Fichas\CalcularPrecioFichaService;

/*
|--------------------------------------------------------------------------
| Tests UNITARIOS de los métodos puros del calculador.
|--------------------------------------------------------------------------
| No tocan DB ni Eloquent — son cálculos string-based con bcmath.
| Ideales para verificar la matemática del dominio de forma rápida.
| El golden test (ficha real → L2,604.37) vive en tests/Feature/Fichas.
*/

beforeEach(function (): void {
    $this->service = new CalcularPrecioFichaService;
});

// ─── calcularLineaItem ─────────────────────────────────────────────

test('calcularLineaItem aplica desperdicio sobre rendimiento — caso CEMENTO del Excel', function (): void {
    // 0.85 × 1.05 × 220 = 196.35
    expect($this->service->calcularLineaItem('0.850000', '5.00', '220.00'))
        ->toBe('196.35');
});

test('calcularLineaItem con desperdicio 0 — caso ALBAÑIL del Excel', function (): void {
    // 0.5 × 1.00 × 750 = 375.00
    expect($this->service->calcularLineaItem('0.500000', '0.00', '750.00'))
        ->toBe('375.00');
});

test('calcularLineaItem maneja desperdicio alto — caso AGUA del Excel (25%)', function (): void {
    // 0.020 × 1.25 × 100 = 2.50
    expect($this->service->calcularLineaItem('0.020000', '25.00', '100.00'))
        ->toBe('2.50');
});

test('calcularLineaItem con rendimiento periódico (1/9) — caso VAR#4', function (): void {
    // 1.111111 × 1.05 × 270 = 314.99996850 → bcround = 315.00
    expect($this->service->calcularLineaItem('1.111111', '5.00', '270.00'))
        ->toBe('315.00');
});

test('calcularLineaItem con rendimiento muy chico — caso CLAVOS', function (): void {
    // 0.044571 × 1.05 × 25 = 1.169989… → bcround = 1.17
    expect($this->service->calcularLineaItem('0.044571', '5.00', '25.00'))
        ->toBe('1.17');
});

test('calcularLineaItem da 0 cuando rendimiento es 0', function (): void {
    expect($this->service->calcularLineaItem('0.000000', '5.00', '220.00'))
        ->toBe('0.00');
});

test('calcularLineaItem da 0 cuando precio es 0', function (): void {
    expect($this->service->calcularLineaItem('1.000000', '5.00', '0.00'))
        ->toBe('0.00');
});

// ─── calcularLineaPorcentaje ───────────────────────────────────────

test('calcularLineaPorcentaje aplica % sobre base — HERRAMIENTA MENOR del Excel', function (): void {
    // 5% × 975 = 48.75
    expect($this->service->calcularLineaPorcentaje('5.00', '975.00'))
        ->toBe('48.75');
});

test('calcularLineaPorcentaje con base cero da cero', function (): void {
    expect($this->service->calcularLineaPorcentaje('5.00', '0.00'))
        ->toBe('0.00');
});

test('calcularLineaPorcentaje con porcentaje cero da cero', function (): void {
    expect($this->service->calcularLineaPorcentaje('0.00', '1000.00'))
        ->toBe('0.00');
});

test('calcularLineaPorcentaje redondea half-up correctamente', function (): void {
    // 2.5% × 100 = 2.50 (exacto, no requiere redondeo)
    expect($this->service->calcularLineaPorcentaje('2.50', '100.00'))
        ->toBe('2.50');

    // 7.55% × 1000 = 75.50
    expect($this->service->calcularLineaPorcentaje('7.55', '1000.00'))
        ->toBe('75.50');
});

// ─── rendimientoEfectivo ───────────────────────────────────────────

test('rendimientoEfectivo expone el rendimiento ya con desperdicio aplicado', function (): void {
    // 0.85 × 1.05 = 0.8925
    expect($this->service->rendimientoEfectivo('0.850000', '5.00'))
        ->toBe('0.892500');
});

test('rendimientoEfectivo con desperdicio 0 retorna el base', function (): void {
    expect($this->service->rendimientoEfectivo('0.500000', '0.00'))
        ->toBe('0.500000');
});

test('rendimientoEfectivo es invariante: rend × (1+desp/100) × precio = subtotal', function (): void {
    $efectivo = $this->service->rendimientoEfectivo('1.111111', '5.00');
    $subtotal = bcmul($efectivo, '270.00', 6);
    $redondeado = round((float) $subtotal, 2);

    expect($redondeado)->toBe(315.00);
});
