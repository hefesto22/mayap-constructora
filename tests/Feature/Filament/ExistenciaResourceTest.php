<?php

declare(strict_types=1);

use App\Filament\Resources\Existencias\Pages\ListExistencias;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Tests Livewire del ExistenciaResource (vista de stock + registrar entrada).
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

test('ExistenciaResource: lista renderiza sin error', function (): void {
    Existencia::factory()->count(3)->create();

    Livewire::test(ListExistencias::class)->assertSuccessful();
});

test('la acción Registrar entrada crea existencia y aplica el costo promedio', function (): void {
    $item = Item::factory()->create();
    $bodega = Bodega::factory()->create();

    Livewire::test(ListExistencias::class)
        ->callAction('registrar_entrada', [
            'item_id'        => $item->id,
            'bodega_id'      => $bodega->id,
            'cantidad'       => '25',
            'costo_unitario' => '8',
        ])
        ->assertHasNoActionErrors();

    $existencia = Existencia::query()
        ->where('item_id', $item->id)
        ->where('bodega_id', $bodega->id)
        ->firstOrFail();

    expect($existencia->cantidad)->toBe('25.0000')
        ->and($existencia->valor_total)->toBe('200.00')
        ->and($existencia->costo_promedio)->toBe('8.00');
});

test('dos entradas del mismo item recalculan el promedio ponderado', function (): void {
    $item = Item::factory()->create();
    $bodega = Bodega::factory()->create();

    Livewire::test(ListExistencias::class)
        ->callAction('registrar_entrada', [
            'item_id'        => $item->id,
            'bodega_id'      => $bodega->id,
            'cantidad'       => '10',
            'costo_unitario' => '10',
        ])
        ->callAction('registrar_entrada', [
            'item_id'        => $item->id,
            'bodega_id'      => $bodega->id,
            'cantidad'       => '10',
            'costo_unitario' => '20',
        ]);

    $existencia = Existencia::query()
        ->where('item_id', $item->id)
        ->where('bodega_id', $bodega->id)
        ->firstOrFail();

    expect($existencia->cantidad)->toBe('20.0000')
        ->and($existencia->costo_promedio)->toBe('15.00');
});
