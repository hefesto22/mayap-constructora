<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Filament\Resources\Fichas\Pages\EditFicha;
use App\Filament\Resources\Fichas\Pages\ListFichas;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Models\Zona;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Tests Livewire/Filament del FichaResource (Sesión 2 del Sprint 2).
|--------------------------------------------------------------------------
| Cubre:
|  - Render sin error del listado.
|  - Filtros funcionan (zona, activa, cache desactualizado).
|  - Creación de ficha vía form (sin líneas, hereda flujo de Filament).
|  - Acción "Recalcular" individual y bulk actualizan el cache.
|  - Autorización vía policy + Gate::before de super-admin.
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

    $this->zona = Zona::factory()->create(['codigo' => 'SRC', 'nombre' => 'Santa Rosa']);
    $this->unidadM2 = UnidadMedida::factory()->create(['codigo' => 'M2']);
    $this->unidadJDR = UnidadMedida::factory()->create(['codigo' => 'JDR']);
});

// ─── Smoke tests del listado ─────────────────────────────────────

test('FichaResource: lista renderiza sin error sin fichas', function (): void {
    Livewire::test(ListFichas::class)
        ->assertSuccessful();
});

test('FichaResource: lista renderiza sin error con fichas', function (): void {
    Ficha::factory()
        ->count(3)
        ->enZona($this->zona)
        ->conUnidad($this->unidadM2)
        ->create();

    Livewire::test(ListFichas::class)
        ->assertSuccessful();
});

// ─── Filtros ─────────────────────────────────────────────────────

test('FichaResource: filtro por zona funciona', function (): void {
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);

    Ficha::factory()->count(3)->enZona($this->zona)->conUnidad($this->unidadM2)->create();
    Ficha::factory()->count(2)->enZona($tgu)->conUnidad($this->unidadM2)->create();

    Livewire::test(ListFichas::class)
        ->filterTable('zona_id', $this->zona->id)
        ->assertCanSeeTableRecords(Ficha::where('zona_id', $this->zona->id)->get())
        ->assertCanNotSeeTableRecords(Ficha::where('zona_id', $tgu->id)->get());
});

test('FichaResource: filtro por activa muestra solo activas o inactivas', function (): void {
    Ficha::factory()->count(3)->enZona($this->zona)->conUnidad($this->unidadM2)->create();
    Ficha::factory()->count(2)->enZona($this->zona)->conUnidad($this->unidadM2)->inactiva()->create();

    Livewire::test(ListFichas::class)
        ->filterTable('activa', true)
        ->assertCanSeeTableRecords(Ficha::where('activa', true)->get())
        ->assertCanNotSeeTableRecords(Ficha::where('activa', false)->get());
});

test('FichaResource: filtro cache_desactualizado muestra solo fichas con cache stale', function (): void {
    // Ficha sin recalcular → aparece como stale
    $sinRecalcular = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->create();

    // Ficha recalculada al día (no aparece en filtro)
    $alDia = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->create();
    $alDia->forceFill(['precio_calculado_at' => now()])->save();

    Livewire::test(ListFichas::class)
        ->filterTable('cache_desactualizado')
        ->assertCanSeeTableRecords(collect([$sinRecalcular]))
        ->assertCanNotSeeTableRecords(collect([$alDia]));
});

// ─── Acciones del listado ─────────────────────────────────────────

test('FichaResource: action recalcular individual actualiza cache y precio_calculado_at', function (): void {
    $ficha = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->conUtilidad(25.00)->create();
    $albanil = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(800.00)
        ->create();

    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($albanil)
        ->conRendimiento('1.000000', '0.00')
        ->create();

    expect($ficha->precio_calculado_at)->toBeNull();

    Livewire::test(ListFichas::class)
        ->callTableAction('recalcular', $ficha->getKey())
        ->assertHasNoTableActionErrors();

    $ficha->refresh();
    expect((float) $ficha->subtotal_cache)->toBe(800.00);
    expect((float) $ficha->precio_venta_cache)->toBe(1000.00);
    expect($ficha->precio_calculado_at)->not->toBeNull();
});

test('FichaResource: bulk action recalcular_seleccionadas actualiza varias fichas', function (): void {
    $albanil = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(500.00)
        ->create();

    $fichas = Ficha::factory()
        ->count(3)
        ->enZona($this->zona)
        ->conUnidad($this->unidadM2)
        ->conUtilidad(25.00)
        ->create();

    foreach ($fichas as $f) {
        FichaLinea::factory()
            ->paraFicha($f)
            ->conItem($albanil)
            ->conRendimiento('1.000000', '0.00')
            ->create();
    }

    Livewire::test(ListFichas::class)
        ->callTableBulkAction('recalcular_seleccionadas', $fichas->pluck('id')->all())
        ->assertHasNoTableActionErrors();

    foreach ($fichas as $f) {
        $f->refresh();
        expect((float) $f->subtotal_cache)->toBe(500.00);
        expect((float) $f->precio_venta_cache)->toBe(625.00);
        expect($f->precio_calculado_at)->not->toBeNull();
    }
});

// ─── Edit page ───────────────────────────────────────────────────

test('FichaResource: edit page renderiza sin error con ficha existente', function (): void {
    $ficha = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->create();

    Livewire::test(EditFicha::class, ['record' => $ficha->getRouteKey()])
        ->assertSuccessful();
});

test('FichaResource: edit header action recalcular dispara el service', function (): void {
    $ficha = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->conUtilidad(25.00)->create();
    $albanil = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(1000.00)
        ->create();

    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($albanil)
        ->conRendimiento('1.000000', '0.00')
        ->create();

    Livewire::test(EditFicha::class, ['record' => $ficha->getRouteKey()])
        ->callAction('recalcular')
        ->assertHasNoActionErrors();

    $ficha->refresh();
    expect((float) $ficha->precio_venta_cache)->toBe(1250.00); // 1000 + 25%
});

// ─── Autorización ────────────────────────────────────────────────

test('FichaResource: usuario sin permisos no puede acceder al listado', function (): void {
    auth()->logout();

    $userSinPermisos = User::factory()->create(['is_active' => true]);
    // NO le asignamos rol super_admin
    $this->actingAs($userSinPermisos);

    // El Gate::before ya está registrado en beforeEach pero solo aplica a super_admin.
    // Este usuario debe ser denegado por la Policy.
    Livewire::test(ListFichas::class)
        ->assertForbidden();
});
