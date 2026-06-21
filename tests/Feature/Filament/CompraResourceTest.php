<?php

declare(strict_types=1);

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\Proveedor;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(Utils::getSuperAdminName());

    Gate::before(function ($user): ?bool {
        return $user instanceof User && $user->hasRole(Utils::getSuperAdminName())
            ? true
            : null;
    });

    $this->actingAs($this->admin);

    $this->proveedor = Proveedor::factory()->create();
    $this->bodega = Bodega::factory()->create();
});

test('CompraResource: lista renderiza sin error', function (): void {
    Compra::factory()->count(3)->create();

    Livewire::test(ListCompras::class)->assertSuccessful();
});

test('CompraResource: crea una compra con líneas en borrador', function (): void {
    $material = Material::factory()->create();

    Livewire::test(CreateCompra::class)
        ->fillForm([
            'proveedor_id'   => $this->proveedor->id,
            'bodega_id'      => $this->bodega->id,
            'fecha'          => '2026-06-18',
            'condicion_pago' => CondicionPago::Contado->value,
            'aplica_isv'     => true,
            'lineas'         => [
                ['material_id' => $material->id, 'cantidad' => '100', 'costo_unitario' => '10'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $compra = Compra::query()->firstOrFail();

    expect($compra->codigo)->toStartWith('COM-2026-')
        ->and($compra->estado)->toBe(EstadoCompra::Borrador)
        ->and($compra->lineas)->toHaveCount(1);
});

test('al elegir un proveedor a crédito, la compra hereda su condición de pago', function (): void {
    $proveedorCredito = Proveedor::factory()->aCredito(30)->create();

    Livewire::test(CreateCompra::class)
        ->fillForm(['proveedor_id' => $proveedorCredito->id])
        ->assertFormSet(['condicion_pago' => CondicionPago::Credito->value]);
});

test('la acción Confirmar registra el stock y marca la compra confirmada', function (): void {
    $material = Material::factory()->create();
    $compra = Compra::factory()->paraBodega($this->bodega)->create(['aplica_isv' => true, 'isv_porcentaje' => 15]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 50, 'costo_unitario' => 20,
    ]);

    Livewire::test(ListCompras::class)
        ->callTableAction('confirmar', $compra)
        ->assertHasNoTableActionErrors();

    expect($compra->fresh()->estado)->toBe(EstadoCompra::Confirmada);

    $existencia = Existencia::query()
        ->where('material_id', $material->id)
        ->where('bodega_id', $this->bodega->id)
        ->firstOrFail();

    expect($existencia->cantidad)->toBe('50.0000')
        ->and($existencia->costo_promedio)->toBe('20.00');
});
