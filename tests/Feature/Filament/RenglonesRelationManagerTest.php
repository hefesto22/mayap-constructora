<?php

declare(strict_types=1);

use App\Filament\Resources\Proyectos\Pages\EditProyecto;
use App\Filament\Resources\Proyectos\RelationManagers\RenglonesRelationManager;
use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Models\Zona;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(Utils::getSuperAdminName());

    Gate::before(fn ($user): ?bool => $user instanceof User && $user->hasRole(Utils::getSuperAdminName()) ? true : null);

    $this->actingAs($this->admin);

    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->unidad = UnidadMedida::factory()->create();
    $this->ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create(['precio_venta_cache' => '1000.00']);
});

test('el RelationManager de renglones renderiza con sus renglones', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->create();

    $renglon = ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('10', '1000.00')
        ->create();

    Livewire::test(RenglonesRelationManager::class, [
        'ownerRecord' => $proyecto,
        'pageClass'   => EditProyecto::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$renglon]);
});

test('el RelationManager renderiza en solo lectura cuando el proyecto no es borrador', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->aprobada()->create();

    Livewire::test(RenglonesRelationManager::class, [
        'ownerRecord' => $proyecto,
        'pageClass'   => EditProyecto::class,
    ])->assertSuccessful();
});
