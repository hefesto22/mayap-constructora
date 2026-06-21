<?php

declare(strict_types=1);

use App\Enums\EstadoCuentaPorCobrar;
use App\Exceptions\Cobranza\CobroInvalidoException;
use App\Models\Cobro;
use App\Models\CuentaPorCobrar;
use App\Services\Cobranza\CobrarService;

/*
|--------------------------------------------------------------------------
| Golden tests del motor de cobros: baja de saldo y recálculo de estado.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = new CobrarService;
});

test('un cobro parcial baja el saldo y marca la cuenta como parcial', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 10000]);

    $cobro = $this->service->cobrar($cuenta, '4000');

    expect($cobro)->toBeInstanceOf(Cobro::class)
        ->and($cobro->monto)->toBe('4000.00');

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('6000.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorCobrar::Parcial);
});

test('un cobro que cubre el saldo marca la cuenta como pagada', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 10000]);

    $this->service->cobrar($cuenta, '10000');

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('0.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorCobrar::Pagada);
});

test('cobros sucesivos acumulan correctamente', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 10000]);

    $this->service->cobrar($cuenta, '3000');
    $this->service->cobrar($cuenta, '2000');

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('5000.00')
        ->and($cuenta->cobros()->count())->toBe(2);
});

test('un cobro que excede el saldo es rechazado sin crear registros', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 6000]);

    expect(fn () => $this->service->cobrar($cuenta, '7000'))
        ->toThrow(CobroInvalidoException::class);

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('6000.00')
        ->and(Cobro::query()->count())->toBe(0);
});

test('un cobro de monto cero o negativo es rechazado', function (string $monto): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 10000]);

    expect(fn () => $this->service->cobrar($cuenta, $monto))
        ->toThrow(CobroInvalidoException::class);

    expect(Cobro::query()->count())->toBe(0);
})->with(['0', '-50']);
