<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Filament\Resources\Zonas\Pages\CreateZona;
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
| Heredar zona: al crear una zona "desde" otra, se copian los items Y las
| fichas APU. Las fichas destino quedan calculadas con los precios de la
| nueva zona y son independientes de la zona origen.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(Utils::getSuperAdminName());

    Gate::before(fn ($user): ?bool => $user instanceof User && $user->hasRole(Utils::getSuperAdminName()) ? true : null);

    $this->actingAs($this->admin);

    $this->src = Zona::factory()->create(['codigo' => 'SRC', 'nombre' => 'Santa Rosa']);
    $this->unidadM2 = UnidadMedida::factory()->create(['codigo' => 'M2']);
    $this->unidadJDR = UnidadMedida::factory()->create(['codigo' => 'JDR']);
});

test('al heredar una zona se copian los items y las fichas APU', function (): void {
    $cemento = Item::factory()->enZona($this->src)->conUnidad($this->unidadM2)
        ->deCategoria(CategoriaItem::Materiales)->create(['nombre' => 'CEMENTO', 'precio_unitario' => 220]);
    $albanil = Item::factory()->enZona($this->src)->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)->create(['nombre' => 'ALBAÑIL', 'precio_unitario' => 750]);

    $ficha = Ficha::factory()->enZona($this->src)->conUnidad($this->unidadM2)
        ->create(['nombre' => 'LOSA E=10', 'utilidad_porcentaje' => 25]);
    FichaLinea::factory()->paraFicha($ficha)->conItem($cemento)->conRendimiento('0.8925', '5')->create();
    FichaLinea::factory()->paraFicha($ficha)->conItem($albanil)->conRendimiento('0.5', '0')->create();

    Livewire::test(CreateZona::class)
        ->fillForm([
            'codigo'         => 'TGU',
            'nombre'         => 'Tegucigalpa',
            'zona_origen_id' => $this->src->id,
            'copiar_fichas'  => true,
            'activa'         => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tgu = Zona::query()->where('codigo', 'TGU')->first();

    expect($tgu)->not->toBeNull()
        ->and(Item::where('zona_id', $tgu->id)->count())->toBeGreaterThanOrEqual(2)
        ->and(Ficha::where('zona_id', $tgu->id)->count())->toBe(1);

    $copia = Ficha::query()->where('zona_id', $tgu->id)->first();

    expect($copia->lineas()->count())->toBe(2)
        ->and((float) $copia->precio_venta_cache)->toBeGreaterThan(0.0);
});

test('al heredar con el toggle de fichas apagado se copian items pero NO fichas', function (): void {
    $cemento = Item::factory()->enZona($this->src)->conUnidad($this->unidadM2)
        ->deCategoria(CategoriaItem::Materiales)->create(['nombre' => 'CEMENTO', 'precio_unitario' => 220]);

    $ficha = Ficha::factory()->enZona($this->src)->conUnidad($this->unidadM2)
        ->create(['nombre' => 'LOSA E=10', 'utilidad_porcentaje' => 25]);
    FichaLinea::factory()->paraFicha($ficha)->conItem($cemento)->conRendimiento('0.8925', '5')->create();

    Livewire::test(CreateZona::class)
        ->fillForm([
            'codigo'         => 'DLC',
            'nombre'         => 'La Ceiba',
            'zona_origen_id' => $this->src->id,
            'copiar_fichas'  => false,
            'activa'         => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $dlc = Zona::query()->where('codigo', 'DLC')->first();

    expect($dlc)->not->toBeNull()
        ->and(Item::where('zona_id', $dlc->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(Ficha::where('zona_id', $dlc->id)->count())->toBe(0);
});
