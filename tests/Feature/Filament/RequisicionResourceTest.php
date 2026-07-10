<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Requisiciones\Pages\CreateRequisicion;
use App\Filament\Resources\Requisiciones\Pages\ListRequisiciones;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Models\Requisicion;
use App\Models\User;
use App\Models\Zona;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Tests Livewire del RequisicionResource (base: form + tabla).
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

test('RequisicionResource: lista renderiza sin error', function (): void {
    Requisicion::factory()->count(3)->create();

    Livewire::test(ListRequisiciones::class)->assertSuccessful();
});

test('RequisicionResource: crea una requisición con líneas y solicitante', function (): void {
    // El select de materiales solo lista los PRESUPUESTADOS en las fichas
    // de la obra, así que el material debe estar en la composición.
    $zona = Zona::factory()->create();
    $proyecto = Proyecto::factory()->create(['zona_id' => $zona->id]);
    $material = Material::factory()->create();

    $item = Item::factory()
        ->enZona($zona)
        ->deCategoria(CategoriaItem::Materiales)
        ->conMaterial($material)
        ->create();
    $ficha = Ficha::factory()->create(['zona_id' => $zona->id]);
    FichaLinea::factory()->paraFicha($ficha)->conItem($item)->conRendimiento('2.000000')->create();
    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($ficha)
        ->conCantidad('100.0000', '500.00')
        ->create();

    Livewire::test(CreateRequisicion::class)
        ->fillForm([
            'proyecto_id'     => $proyecto->id,
            'fecha_solicitud' => '2026-06-18',
            'fecha_necesaria' => '2026-06-25',
            'lineas'          => [
                ['material_id' => $material->id, 'cantidad_solicitada' => '100'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $requisicion = Requisicion::query()->firstOrFail();

    expect($requisicion->codigo)->toStartWith('REQ-2026-')
        ->and($requisicion->estado)->toBe(EstadoRequisicion::Solicitada)
        ->and($requisicion->solicitante_id)->toBe($this->admin->id)
        ->and($requisicion->lineas)->toHaveCount(1)
        ->and($requisicion->lineas->first()->cantidad_solicitada)->toBe('100.0000');
});
