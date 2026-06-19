<?php

declare(strict_types=1);

use App\Enums\CondicionPago;
use App\Filament\Resources\Proveedores\Pages\CreateProveedor;
use App\Filament\Resources\Proveedores\Pages\ListProveedores;
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
});

test('ProveedorResource: lista renderiza sin error', function (): void {
    Proveedor::factory()->count(3)->create();

    Livewire::test(ListProveedores::class)->assertSuccessful();
});

test('ProveedorResource: crea un proveedor con auto-código y mayúsculas', function (): void {
    Livewire::test(CreateProveedor::class)
        ->fillForm([
            'nombre'         => 'ferretería el constructor',
            'condicion_pago' => CondicionPago::Contado->value,
            'activo'         => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $proveedor = Proveedor::query()->firstOrFail();

    expect($proveedor->codigo)->toBe('PRV-00001')
        ->and($proveedor->nombre)->toBe('FERRETERÍA EL CONSTRUCTOR')
        ->and($proveedor->condicion_pago)->toBe(CondicionPago::Contado);
});
