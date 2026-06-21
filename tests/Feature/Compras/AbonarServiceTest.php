<?php

declare(strict_types=1);

use App\Enums\EstadoCuentaPorPagar;
use App\Exceptions\Compras\AbonoInvalidoException;
use App\Models\Abono;
use App\Models\CuentaPorPagar;
use App\Services\Compras\AbonarService;

/*
|--------------------------------------------------------------------------
| Golden test del motor de abonos: baja de saldo y recálculo de estado.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = new AbonarService;
});

test('un abono parcial baja el saldo y marca la cuenta como parcial', function (): void {
    $cuenta = CuentaPorPagar::factory()->create(['monto_original' => 1000, 'saldo' => 1000]);

    $abono = $this->service->abonar($cuenta, '400');

    expect($abono)->toBeInstanceOf(Abono::class)
        ->and($abono->monto)->toBe('400.00');

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('600.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorPagar::Parcial);
});

test('un abono que cubre el saldo marca la cuenta como pagada', function (): void {
    $cuenta = CuentaPorPagar::factory()->create(['monto_original' => 1000, 'saldo' => 1000]);

    $this->service->abonar($cuenta, '1000');

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('0.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorPagar::Pagada);
});

test('abonos sucesivos acumulan correctamente hasta saldar', function (): void {
    $cuenta = CuentaPorPagar::factory()->create(['monto_original' => 1000, 'saldo' => 1000]);

    $this->service->abonar($cuenta, '300');
    $this->service->abonar($cuenta, '200');

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('500.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorPagar::Parcial)
        ->and($cuenta->abonos()->count())->toBe(2);
});

test('un abono que excede el saldo es rechazado sin crear registros', function (): void {
    $cuenta = CuentaPorPagar::factory()->create(['monto_original' => 1000, 'saldo' => 600]);

    expect(fn () => $this->service->abonar($cuenta, '700'))
        ->toThrow(AbonoInvalidoException::class);

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('600.00')
        ->and(Abono::query()->count())->toBe(0);
});

test('un abono de monto cero o negativo es rechazado', function (string $monto): void {
    $cuenta = CuentaPorPagar::factory()->create(['monto_original' => 1000, 'saldo' => 1000]);

    expect(fn () => $this->service->abonar($cuenta, $monto))
        ->toThrow(AbonoInvalidoException::class);

    expect(Abono::query()->count())->toBe(0);
})->with(['0', '-50']);
