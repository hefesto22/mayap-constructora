<?php

declare(strict_types=1);

use App\Enums\EstadoCuentaPorCobrar;
use App\Exceptions\Cobranza\CobroInvalidoException;
use App\Models\CuentaPorCobrar;
use App\Services\Cobranza\AjustarCuentaPorCobrarService;
use App\Services\Cobranza\CobrarService;

/*
|--------------------------------------------------------------------------
| Golden tests del ajuste al alza de cuentas por cobrar (extras de renta
| y extensiones): monto y saldo suben JUNTOS y el estado se recalcula.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(AjustarCuentaPorCobrarService::class);
});

test('aumentar sube monto original y saldo juntos', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 10000]);

    $this->service->aumentar($cuenta, '2185', 'HORAS EXTRA');

    $cuenta->refresh();
    expect($cuenta->monto_original)->toBe('12185.00')
        ->and($cuenta->saldo)->toBe('12185.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorCobrar::Pendiente);
});

test('una cuenta pagada que recibe un extra vuelve a parcial', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 10000]);

    app(CobrarService::class)->cobrar($cuenta, '10000');
    expect($cuenta->refresh()->estado)->toBe(EstadoCuentaPorCobrar::Pagada);

    $this->service->aumentar($cuenta, '1500', 'EXTENSIÓN DE RENTA');

    $cuenta->refresh();
    expect($cuenta->estado)->toBe(EstadoCuentaPorCobrar::Parcial)
        ->and($cuenta->monto_original)->toBe('11500.00')
        ->and($cuenta->saldo)->toBe('1500.00');
});

test('un aumento no positivo es rechazado', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 10000]);

    expect(fn () => $this->service->aumentar($cuenta, '0', 'NADA'))
        ->toThrow(CobroInvalidoException::class);

    expect(fn () => $this->service->aumentar($cuenta, '-100', 'NEGATIVO'))
        ->toThrow(CobroInvalidoException::class);
});

test('el ajuste queda en la bitácora con montos y motivo', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 10000]);

    $this->service->aumentar($cuenta, '2000', 'HORAS EXTRA AL FINALIZAR RENTA');

    $this->assertDatabaseHas('activity_log', [
        'log_name'   => 'cobranza',
        'event'      => 'cuenta_aumentada',
        'subject_id' => $cuenta->id,
    ]);
});
