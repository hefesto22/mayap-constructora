<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Permisos;
use Database\Seeders\RolesInventarioSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Roles de operación del inventario (Fase 2): bodeguero y gerencia.
|--------------------------------------------------------------------------
| El bodeguero controla todo lo que entra/sale de SU bodega pero NO
| administra el catálogo global. Gerencia administra todo y ve todas las
| bodegas. Ambos pueden acceder al panel.
*/

function crearPermisosShieldDemo(): void
{
    // Formato real de Shield: {Accion}:{Modelo} en PascalCase.
    $permisos = [
        'ViewAny:Existencia', 'View:Existencia', 'Create:Existencia', 'Update:Existencia', 'Delete:Existencia',
        'ViewAny:Compra', 'View:Compra', 'Create:Compra', 'Update:Compra', 'Delete:Compra',
        'ViewAny:Requisicion', 'View:Requisicion', 'Create:Requisicion', 'Update:Requisicion',
        'ViewAny:Material', 'View:Material', 'Create:Material', 'Update:Material', 'Delete:Material',
        'ViewAny:Bodega', 'View:Bodega', 'Create:Bodega', 'Update:Bodega',
        'ViewAny:Proveedor', 'View:Proveedor', 'Create:Proveedor',
        'ViewAny:CuentaPorPagar', 'Create:CuentaPorPagar',
        Permisos::VER_TODAS_LAS_BODEGAS, 'View:MyProfilePage',
    ];

    foreach ($permisos as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }
}

test('el rol bodeguero controla su bodega pero NO administra el catálogo global', function (): void {
    crearPermisosShieldDemo();

    (new RolesInventarioSeeder)->run();

    $bodeguero = Role::findByName('bodeguero', 'web');

    expect($bodeguero->hasPermissionTo('ViewAny:Existencia', 'web'))->toBeTrue()
        ->and($bodeguero->hasPermissionTo('Create:Compra', 'web'))->toBeTrue()
        ->and($bodeguero->hasPermissionTo('Update:Requisicion', 'web'))->toBeTrue()
        // Catálogo de materiales: SOLO lectura (no crea/edita/borra).
        ->and($bodeguero->hasPermissionTo('ViewAny:Material', 'web'))->toBeTrue()
        ->and($bodeguero->hasPermissionTo('Create:Material', 'web'))->toBeFalse()
        ->and($bodeguero->hasPermissionTo('Delete:Material', 'web'))->toBeFalse()
        // No ve todas las bodegas: queda restringido a las suyas.
        ->and($bodeguero->hasPermissionTo(Permisos::VER_TODAS_LAS_BODEGAS, 'web'))->toBeFalse();
});

test('el rol gerencia administra todo el inventario y ve todas las bodegas', function (): void {
    crearPermisosShieldDemo();

    (new RolesInventarioSeeder)->run();

    $gerencia = Role::findByName('gerencia', 'web');

    expect($gerencia->hasPermissionTo(Permisos::VER_TODAS_LAS_BODEGAS, 'web'))->toBeTrue()
        ->and($gerencia->hasPermissionTo('Create:Material', 'web'))->toBeTrue()
        ->and($gerencia->hasPermissionTo('ViewAny:Existencia', 'web'))->toBeTrue();
});

test('usuarios con rol gerencia o bodeguero pueden acceder al panel; inactivos no', function (): void {
    Role::findOrCreate('bodeguero', 'web');
    Role::findOrCreate('gerencia', 'web');

    $panel = Filament::getDefaultPanel();

    $bodeguero = User::factory()->create(['is_active' => true]);
    $bodeguero->assignRole('bodeguero');

    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->assignRole('gerencia');

    $inactivo = User::factory()->create(['is_active' => false]);
    $inactivo->assignRole('bodeguero');

    expect($bodeguero->canAccessPanel($panel))->toBeTrue()
        ->and($gerente->canAccessPanel($panel))->toBeTrue()
        ->and($inactivo->canAccessPanel($panel))->toBeFalse();
});
