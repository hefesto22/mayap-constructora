<?php

declare(strict_types=1);

use App\Filament\Resources\Bodegas\Pages\CreateBodega;
use App\Filament\Resources\Bodegas\Pages\ListBodegas;
use App\Models\Bodega;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Tests Livewire/Filament del BodegaResource (Filament 1 — módulo Inventario).
|--------------------------------------------------------------------------
| Cubre el render del listado y la creación vía formulario con auto-código.
| Autorización por Gate::before de super-admin (RefreshDatabase no genera
| los permisos individuales de Shield).
*/

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

test('BodegaResource: lista renderiza sin error sin bodegas', function (): void {
    Livewire::test(ListBodegas::class)->assertSuccessful();
});

test('BodegaResource: lista renderiza sin error con bodegas', function (): void {
    Bodega::factory()->count(3)->create();

    Livewire::test(ListBodegas::class)->assertSuccessful();
});

test('BodegaResource: crea una bodega con auto-código y nombre en mayúsculas', function (): void {
    Livewire::test(CreateBodega::class)
        ->fillForm([
            'nombre'      => 'bodega central santa rosa',
            'responsable' => 'Juan Pérez',
            'direccion'   => 'barrio el centro',
            'activo'      => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $bodega = Bodega::query()->firstOrFail();

    expect($bodega->codigo)->toBe('BOD-00001')
        ->and($bodega->nombre)->toBe('BODEGA CENTRAL SANTA ROSA')
        ->and($bodega->direccion)->toBe('BARRIO EL CENTRO')
        ->and($bodega->responsable)->toBe('Juan Pérez');
});
