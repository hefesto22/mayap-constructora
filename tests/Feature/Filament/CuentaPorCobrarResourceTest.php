<?php

declare(strict_types=1);

use App\Enums\EstadoCuentaPorCobrar;
use App\Filament\Resources\CuentasPorCobrar\Pages\CreateCuentaPorCobrar;
use App\Filament\Resources\CuentasPorCobrar\Pages\ListCuentasPorCobrar;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
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
});

test('CuentaPorCobrarResource: lista renderiza sin error', function (): void {
    CuentaPorCobrar::factory()->count(3)->create();

    Livewire::test(ListCuentasPorCobrar::class)->assertSuccessful();
});

test('al crear una cuenta, el saldo inicia igual al monto', function (): void {
    $cliente = Cliente::factory()->create();

    Livewire::test(CreateCuentaPorCobrar::class)
        ->fillForm([
            'cliente_id'        => $cliente->id,
            'concepto'          => 'ANTICIPO',
            'monto_original'    => '50000',
            'fecha_emision'     => '2026-06-21',
            'fecha_vencimiento' => '2026-07-21',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $cuenta = CuentaPorCobrar::query()->firstOrFail();

    expect($cuenta->codigo)->toStartWith('CXC-2026-')
        ->and($cuenta->saldo)->toBe('50000.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorCobrar::Pendiente);
});

test('la acción Cobrar reduce el saldo y actualiza el estado', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 10000]);

    Livewire::test(ListCuentasPorCobrar::class)
        ->callTableAction('cobrar', $cuenta, ['monto' => '4000', 'fecha' => '2026-06-21'])
        ->assertHasNoTableActionErrors();

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('6000.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorCobrar::Parcial)
        ->and($cuenta->cobros()->count())->toBe(1);
});

test('la acción Cobrar rechaza un monto mayor al saldo', function (): void {
    $cuenta = CuentaPorCobrar::factory()->create(['monto_original' => 10000, 'saldo' => 3000]);

    Livewire::test(ListCuentasPorCobrar::class)
        ->callTableAction('cobrar', $cuenta, ['monto' => '5000', 'fecha' => '2026-06-21'])
        ->assertHasTableActionErrors(['monto']);

    expect($cuenta->fresh()->saldo)->toBe('3000.00');
});
