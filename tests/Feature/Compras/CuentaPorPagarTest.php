<?php

declare(strict_types=1);

use App\Enums\EstadoCuentaPorPagar;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\CuentaPorPagar;
use App\Models\Material;
use App\Models\Proveedor;
use App\Services\Compras\ConfirmarCompraService;

/*
|--------------------------------------------------------------------------
| Tests del hook que genera la cuenta por pagar al confirmar compra a crédito.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->confirmar = app(ConfirmarCompraService::class);
    $this->bodega = Bodega::factory()->create();
});

test('confirmar una compra a crédito genera la cuenta por pagar', function (): void {
    $proveedor = Proveedor::factory()->aCredito(30)->create();
    $material = Material::factory()->create();

    $compra = Compra::factory()
        ->paraProveedor($proveedor)
        ->paraBodega($this->bodega)
        ->aCredito()
        ->create(['fecha' => '2026-06-18', 'aplica_isv' => true, 'isv_porcentaje' => 15]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 10, 'costo_unitario' => 100,
    ]);

    $this->confirmar->confirmar($compra);

    // Subtotal 1000 + ISV 150 = total 1150.
    $cuenta = CuentaPorPagar::query()->where('compra_id', $compra->id)->firstOrFail();

    expect($cuenta->monto_original)->toBe('1150.00')
        ->and($cuenta->saldo)->toBe('1150.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorPagar::Pendiente)
        ->and($cuenta->fecha_vencimiento->format('Y-m-d'))->toBe('2026-07-18'); // +30 días
});

test('confirmar una compra al contado NO genera cuenta por pagar', function (): void {
    $material = Material::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create(); // contado por defecto
    CompraLinea::factory()->create(['compra_id' => $compra->id, 'material_id' => $material->id]);

    $this->confirmar->confirmar($compra);

    expect(CuentaPorPagar::query()->where('compra_id', $compra->id)->exists())->toBeFalse();
});
