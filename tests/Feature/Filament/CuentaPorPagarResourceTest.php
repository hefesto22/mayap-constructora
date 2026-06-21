<?php

declare(strict_types=1);

use App\Enums\EstadoCuentaPorPagar;
use App\Filament\Resources\CuentasPorPagar\Pages\ListCuentasPorPagar;
use App\Models\CuentaPorPagar;
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

test('CuentaPorPagarResource: lista renderiza sin error', function (): void {
    CuentaPorPagar::factory()->count(3)->create();

    Livewire::test(ListCuentasPorPagar::class)->assertSuccessful();
});

test('la acción Abonar reduce el saldo y actualiza el estado', function (): void {
    $cuenta = CuentaPorPagar::factory()->create(['monto_original' => 1000, 'saldo' => 1000]);

    Livewire::test(ListCuentasPorPagar::class)
        ->callTableAction('abonar', $cuenta, ['monto' => '400', 'fecha' => '2026-06-18'])
        ->assertHasNoTableActionErrors();

    $cuenta->refresh();
    expect($cuenta->saldo)->toBe('600.00')
        ->and($cuenta->estado)->toBe(EstadoCuentaPorPagar::Parcial)
        ->and($cuenta->abonos()->count())->toBe(1);
});

test('la acción Abonar no se ofrece en cuentas ya pagadas', function (): void {
    $cuenta = CuentaPorPagar::factory()->create([
        'monto_original' => 1000,
        'saldo'          => 0,
        'estado'         => EstadoCuentaPorPagar::Pagada,
    ]);

    Livewire::test(ListCuentasPorPagar::class)
        ->assertTableActionHidden('abonar', $cuenta);
});

test('la acción Abonar rechaza un monto mayor al saldo', function (): void {
    $cuenta = CuentaPorPagar::factory()->create(['monto_original' => 1000, 'saldo' => 300]);

    Livewire::test(ListCuentasPorPagar::class)
        ->callTableAction('abonar', $cuenta, ['monto' => '500', 'fecha' => '2026-06-18'])
        ->assertHasTableActionErrors(['monto']);

    expect($cuenta->fresh()->saldo)->toBe('300.00');
});
