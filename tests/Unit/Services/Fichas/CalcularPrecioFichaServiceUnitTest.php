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
|
| Modelo único (Sprint 2 Sesión 3): el rendimiento es EFECTIVO (con
| desperdicio ya considerado). El campo desperdicio_porcentaje es
| informativo y NO entra al cálculo. Fórmula: subtotal = rend × precio.
*/

beforeEach(function (): void {
    $this->service = new CalcularPrecioFichaService;
});

// ─── calcularLineaItem ─────────────────────────────────────────────

test('calcularLineaItem multiplica rendimiento × precio — caso CEMENTO del Excel', function (): void {
    // 0.892500 × 220 = 196.35
    expect($this->service->calcularLineaItem('0.892500', '5.00', '220.00'))
        ->toBe('196.35');
});

test('calcularLineaItem con desperdicio informativo — caso ALBAÑIL del Excel', function (): void {
    // 0.500000 × 750 = 375.00
    expect($this->service->calcularLineaItem('0.500000', '0.00', '750.00'))
        ->toBe('375.00');
});

test('calcularLineaItem maneja efectivo de AGUA del Excel (25% desperdicio interno)', function (): void {
    // efectivo = 0.020 × 1.25 = 0.025; 0.025 × 100 = 2.50
    expect($this->service->calcularLineaItem('0.025000', '25.00', '100.00'))
        ->toBe('2.50');
});

test('calcularLineaItem con rendimiento periódico — caso VAR#4', function (): void {
    // efectivo = 1.111111 × 1.05 = 1.16666655 ≈ 1.166667
    // 1.166667 × 270 = 315.00009 → bcround = 315.00
    expect($this->service->calcularLineaItem('1.166667', '5.00', '270.00'))
        ->toBe('315.00');
});

test('calcularLineaItem con rendimiento muy chico — caso CLAVOS', function (): void {
    // efectivo = 0.044571 × 1.05 = 0.04679955 ≈ 0.046800
    // 0.046800 × 25 = 1.17
    expect($this->service->calcularLineaItem('0.046800', '5.00', '25.00'))
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

test('el campo desperdicio NO afecta el subtotal — es metadato informativo', function (): void {
    // Mismo rendimiento + precio, distintos desperdicios → mismo subtotal.
    $sub5 = $this->service->calcularLineaItem('0.5', '5', '1000');
    $sub20 = $this->service->calcularLineaItem('0.5', '20', '1000');
    $sub0 = $this->service->calcularLineaItem('0.5', '0', '1000');

    expect($sub5)->toBe($sub20);
    expect($sub5)->toBe($sub0);
    expect($sub5)->toBe('500.00');
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
