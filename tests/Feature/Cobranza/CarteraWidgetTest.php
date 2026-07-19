<?php

declare(strict_types=1);

use App\Filament\Widgets\CarteraWidget;
use App\Models\CuentaPorCobrar;
use App\Services\Cobranza\CobrarService;

/*
|--------------------------------------------------------------------------
| Golden tests de los números del widget de cartera: saldo total,
| vencidas, por vencer en 7 días y cobrado del mes.
|--------------------------------------------------------------------------
*/

test('la cartera suma saldos y clasifica vencidas y por vencer', function (): void {
    // Vencida hace 5 días, saldo 10,000.
    CuentaPorCobrar::factory()->create([
        'monto_original'    => 10000,
        'saldo'             => 10000,
        'fecha_emision'     => today()->subDays(30),
        'fecha_vencimiento' => today()->subDays(5),
    ]);

    // Vence en 3 días, saldo 4,000.
    CuentaPorCobrar::factory()->create([
        'monto_original'    => 4000,
        'saldo'             => 4000,
        'fecha_emision'     => today()->subDays(10),
        'fecha_vencimiento' => today()->addDays(3),
    ]);

    // Lejana (vence en 30 días), saldo 6,000 — cuenta en el total, no en radares.
    CuentaPorCobrar::factory()->create([
        'monto_original'    => 6000,
        'saldo'             => 6000,
        'fecha_emision'     => today(),
        'fecha_vencimiento' => today()->addDays(30),
    ]);

    // Pagada: fuera de todo.
    CuentaPorCobrar::factory()->create([
        'monto_original'    => 2000,
        'saldo'             => 0,
        'estado'            => 'pagada',
        'fecha_emision'     => today()->subDays(20),
        'fecha_vencimiento' => today()->subDays(1),
    ]);

    $datos = CarteraWidget::datos();

    expect($datos['saldo_total'])->toBe(20000.0)
        ->and($datos['cuentas_con_saldo'])->toBe(3)
        ->and($datos['vencidas_monto'])->toBe(10000.0)
        ->and($datos['vencidas_count'])->toBe(1)
        ->and($datos['por_vencer_monto'])->toBe(4000.0)
        ->and($datos['por_vencer_count'])->toBe(1);
});

test('el cobrado del mes suma solo los cobros desde el día 1', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create([
        'monto_original'    => 10000,
        'saldo'             => 10000,
        'fecha_emision'     => today()->subDays(45),
        'fecha_vencimiento' => today()->addDays(5),
    ]);

    app(CobrarService::class)->cobrar($cuenta, '3000', today()->toDateString());
    app(CobrarService::class)->cobrar($cuenta, '1500', today()->startOfMonth()->subDay()->toDateString());

    expect(CarteraWidget::datos()['cobrado_mes'])->toBe(3000.0);
});
