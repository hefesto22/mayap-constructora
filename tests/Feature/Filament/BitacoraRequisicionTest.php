<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Requisiciones\Pages\ViewRequisicion;
use App\Filament\Resources\Requisiciones\RelationManagers\TransicionesRelationManager;
use App\Models\Requisicion;
use App\Models\RequisicionTransicion;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Tests de la bitácora de requisiciones (página de vista + RelationManager).
|--------------------------------------------------------------------------
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

test('la página de vista de la requisición renderiza sin error', function (): void {
    $requisicion = Requisicion::factory()->create();

    Livewire::test(ViewRequisicion::class, ['record' => $requisicion->getRouteKey()])
        ->assertSuccessful();
});

test('la bitácora muestra las transiciones registradas', function (): void {
    $requisicion = Requisicion::factory()->create();

    $transicion = RequisicionTransicion::factory()->create([
        'requisicion_id' => $requisicion->id,
        'estado_origen'  => EstadoRequisicion::Solicitada->value,
        'estado_destino' => EstadoRequisicion::Autorizada->value,
        'user_id'        => $this->admin->id,
        'nota'           => 'autorizado por el ingeniero',
    ]);

    Livewire::test(TransicionesRelationManager::class, [
        'ownerRecord' => $requisicion,
        'pageClass'   => ViewRequisicion::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$transicion]);
});
