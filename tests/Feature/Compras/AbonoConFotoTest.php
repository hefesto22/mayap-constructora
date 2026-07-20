<?php

declare(strict_types=1);

use App\Enums\EstadoCuentaPorPagar;
use App\Models\CuentaPorPagar;
use App\Services\Compras\AbonarService;

/*
|--------------------------------------------------------------------------
| Foto del comprobante de transferencia por abono (decisión Mauricio
| 2026-07-20): una por abono, guardada por AbonarService — la única
| puerta que mueve el saldo.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(AbonarService::class);

    $this->cuenta = CuentaPorPagar::factory()->create([
        'monto_original' => 10000,
        'saldo'          => 10000,
    ]);
});

test('el abono guarda la foto del comprobante', function (): void {
    $abono = $this->service->abonar(
        cuenta: $this->cuenta,
        monto: '4000.00',
        metodo: 'TRANSFERENCIA',
        referencia: 'TRF-889900',
        fotoComprobante: 'comprobantes/2026-07/abc123.webp',
    );

    expect($abono->refresh()->foto_comprobante)->toBe('comprobantes/2026-07/abc123.webp')
        ->and((string) $this->cuenta->refresh()->saldo)->toBe('6000.00')
        ->and($this->cuenta->estado)->toBe(EstadoCuentaPorPagar::Parcial);
});

test('el abono sin comprobante queda con la columna vacía', function (): void {
    $abono = $this->service->abonar(
        cuenta: $this->cuenta,
        monto: '10000.00',
        metodo: 'EFECTIVO',
    );

    expect($abono->refresh()->foto_comprobante)->toBeNull()
        ->and($this->cuenta->refresh()->estado)->toBe(EstadoCuentaPorPagar::Pagada);
});
