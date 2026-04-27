<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\UnidadesMedida\Pages\ListUnidadesMedida;
use App\Filament\Resources\Zonas\Pages\EditZona;
use App\Filament\Resources\Zonas\Pages\ListZonas;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Models\Zona;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Tests Livewire/Filament básicos del módulo de Catálogos.
 *
 * Cubre:
 *  - Render sin error de los 3 listados (UnidadMedida, Zona, Item).
 *  - Edición inline de precio en ItemResource (TextInputColumn).
 *  - Action de clonado entre zonas dispara ClonarItemsEntreZonas.
 *
 * No reemplaza los tests de dominio del Service — los complementa
 * cubriendo la capa Filament/Livewire que antes no tenía tests.
 *
 * RefreshDatabase se hereda del extend global en tests/Pest.php
 * para todo Feature/* (incluido Feature/Filament).
 */
beforeEach(function (): void {
    // Crear los roles base de Filament Shield.
    Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(Utils::getSuperAdminName());

    // En tests con RefreshDatabase, los permisos individuales de Shield
    // (ViewAny:Item, Create:Zona, etc.) NO se generan porque shield:generate
    // es un comando interactivo separado del seeder de roles. Para evitar
    // depender de eso, registramos un Gate::before que bypaseé policies
    // para super_admin — equivalente al comportamiento de Shield en
    // producción una vez que los permisos están sincronizados.
    Gate::before(function ($user): ?bool {
        return $user instanceof User && $user->hasRole(Utils::getSuperAdminName())
            ? true
            : null;
    });

    $this->actingAs($this->admin);
});

// ─── Smoke tests: los 3 listados renderizan ──────────────────────

test('UnidadMedidaResource: lista renderiza sin error', function (): void {
    UnidadMedida::factory()->count(3)->create();

    Livewire::test(ListUnidadesMedida::class)
        ->assertSuccessful();
});

test('ZonaResource: lista renderiza sin error', function (): void {
    Zona::factory()->count(2)->create();

    Livewire::test(ListZonas::class)
        ->assertSuccessful();
});

test('ItemResource: lista renderiza sin error', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();

    Item::factory()->count(5)->enZona($zona)->conUnidad($unidad)->create();

    Livewire::test(ListItems::class)
        ->assertSuccessful();
});

// ─── ItemResource: edición inline de precio ──────────────────────

test('ItemResource: editar precio inline actualiza precio_actualizado_at', function (): void {
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();

    $item = Item::factory()
        ->enZona($zona)
        ->conUnidad($unidad)
        ->conPrecio(100)
        ->create();

    $stampOriginal = $item->precio_actualizado_at;
    $this->travel(2)->seconds();

    // TextInputColumn usa updateTableColumnState, no callTableColumnAction
    // (este último es para column actions con icono/click, no inputs editables).
    // Ver vendor/filament/tables/src/Concerns/HasColumns.php:43.
    Livewire::test(ListItems::class)
        ->call('updateTableColumnState', 'precio_unitario', (string) $item->getKey(), 250)
        ->assertSuccessful();

    $item->refresh();
    expect((float) $item->precio_unitario)->toBe(250.00);
    expect($item->precio_actualizado_at->greaterThan($stampOriginal))->toBeTrue();
});

test('ItemResource: filtro por zona funciona', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC']);
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $u = UnidadMedida::factory()->create();

    Item::factory()->count(3)->enZona($src)->conUnidad($u)->create();
    Item::factory()->count(2)->enZona($tgu)->conUnidad($u)->create();

    Livewire::test(ListItems::class)
        ->filterTable('zona_id', $src->id)
        ->assertCanSeeTableRecords(Item::where('zona_id', $src->id)->get())
        ->assertCanNotSeeTableRecords(Item::where('zona_id', $tgu->id)->get());
});

test('ItemResource: filtro por categoría funciona', function (): void {
    $zona = Zona::factory()->create();
    $u = UnidadMedida::factory()->create();

    Item::factory()->count(2)->enZona($zona)->conUnidad($u)
        ->deCategoria(CategoriaItem::Materiales)->create();
    Item::factory()->count(1)->enZona($zona)->conUnidad($u)
        ->deCategoria(CategoriaItem::ManoObra)->create();

    Livewire::test(ListItems::class)
        ->filterTable('categoria', [CategoriaItem::Materiales->value])
        ->assertCanSeeTableRecords(
            Item::where('categoria', CategoriaItem::Materiales->value)->get()
        )
        ->assertCanNotSeeTableRecords(
            Item::where('categoria', CategoriaItem::ManoObra->value)->get()
        );
});

// ─── ZonaResource: action de clonado ─────────────────────────────

test('ZonaResource: action clonar_items ejecuta el service y dispara notificación', function (): void {
    $src = Zona::factory()->create(['codigo' => 'SRC', 'nombre' => 'Santa Rosa']);
    $destino = Zona::factory()->create(['codigo' => 'TGU', 'nombre' => 'Tegucigalpa']);
    $unidad = UnidadMedida::factory()->create();

    Item::factory()->count(4)->enZona($src)->conUnidad($unidad)->create();

    Livewire::test(EditZona::class, ['record' => $destino->getRouteKey()])
        ->callAction('clonar_items', data: [
            'zona_origen_id'    => $src->id,
            'saltar_duplicados' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($destino->items()->count())->toBe(4);
    expect($destino->items()->pluck('codigo')->every(fn ($c) => str_starts_with($c, 'TGU-')))
        ->toBeTrue();
});
