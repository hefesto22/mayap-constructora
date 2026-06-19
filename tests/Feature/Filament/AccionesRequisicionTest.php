<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Requisiciones\Pages\ListRequisiciones;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Requisiciones\TransicionarRequisicionService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Tests de las acciones de transición del RequisicionResource (Filament 2b).
|--------------------------------------------------------------------------
| Verifican que las acciones de la tabla llaman al motor: autorizar avanza
| el estado y fija cantidades; despachar mueve stock real con WAC.
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

    $this->inventario = new RegistrarMovimientoService;
    $this->transiciones = new TransicionarRequisicionService($this->inventario);
    $this->bodega = Bodega::factory()->create();
    $this->proyecto = Proyecto::factory()->create();
});

test('la acción Autorizar avanza el estado y fija la cantidad autorizada', function (): void {
    $item = Item::factory()->create();
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    $linea = RequisicionLinea::factory()->create([
        'requisicion_id'      => $requisicion->id,
        'item_id'             => $item->id,
        'cantidad_solicitada' => 100,
    ]);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('autorizar', $requisicion, [
            'lineas' => [[
                'linea_id'            => $linea->id,
                'item'                => 'X',
                'cantidad_solicitada' => '100',
                'cantidad'            => '80',
            ]],
        ])
        ->assertHasNoTableActionErrors();

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Autorizada)
        ->and($linea->fresh()->cantidad_autorizada)->toBe('80.0000');
});

test('la acción Despachar mueve stock real de la bodega a la obra', function (): void {
    $item = Item::factory()->create();
    $this->inventario->entradaCompra($item->id, Ubicacion::bodega($this->bodega->id), '200', '10');

    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    RequisicionLinea::factory()->create([
        'requisicion_id'      => $requisicion->id,
        'item_id'             => $item->id,
        'cantidad_solicitada' => 100,
    ]);

    // Autorizar vía Service (precondición del despacho).
    $this->transiciones->autorizar($requisicion);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('despachar', $requisicion, [
            'bodega_id' => $this->bodega->id,
            'nota'      => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Despachada);

    $stockBodega = Existencia::query()
        ->where('item_id', $item->id)
        ->where('bodega_id', $this->bodega->id)
        ->value('cantidad');

    $stockObra = Existencia::query()
        ->where('item_id', $item->id)
        ->where('proyecto_id', $this->proyecto->id)
        ->value('cantidad');

    expect((string) $stockBodega)->toBe('100.0000')
        ->and((string) $stockObra)->toBe('100.0000');
});

test('Despachar sin stock manda la requisición a Requisición de compra', function (): void {
    $item = Item::factory()->create(); // sin stock
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    RequisicionLinea::factory()->create([
        'requisicion_id'      => $requisicion->id,
        'item_id'             => $item->id,
        'cantidad_solicitada' => 10,
    ]);
    $this->transiciones->autorizar($requisicion);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('despachar', $requisicion, [
            'bodega_id' => $this->bodega->id,
            'nota'      => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::RequisicionCompra);
});
