<?php

declare(strict_types=1);

use App\Filament\Widgets\ObrasPresupuestoWidget;
use App\Models\Bodega;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(Utils::getSuperAdminName());

    Gate::before(fn ($user): ?bool => $user instanceof User && $user->hasRole(Utils::getSuperAdminName())
        ? true
        : null);

    $this->actingAs($this->admin);
});

test('el widget de presupuesto renderiza sin error', function (): void {
    // enEjecucion: desde 2026-08-16 el KPI solo cuenta obras VIVAS — con
    // Borrador contaba cotizaciones y decía "Obras sanas: 43".
    Proyecto::factory()->enEjecucion()->count(2)->create(['subtotal_cache' => 50000]);

    Livewire::test(ObrasPresupuestoWidget::class)->assertSuccessful();
});

test('el widget cuenta una obra en riesgo cuando supera el 80%', function (): void {
    $obra = Proyecto::factory()->enEjecucion()->create(['subtotal_cache' => 10000]);
    $bodega = Bodega::factory()->create();
    $material = Material::factory()->create();

    $inventario = new RegistrarMovimientoService;
    $inventario->entradaCompra(
        materialId: $material->id,
        destino: Ubicacion::bodega($bodega->id),
        cantidad: '90',
        costoUnitario: '100',
    );
    $inventario->salidaDespacho(
        materialId: $material->id,
        origen: Ubicacion::bodega($bodega->id),
        destino: Ubicacion::obra($obra->id),
        cantidad: '90',
    );

    // 9,000 de 10,000 = 90% → en riesgo. El widget muestra el conteo.
    Livewire::test(ObrasPresupuestoWidget::class)
        ->assertSuccessful()
        ->assertSee('En riesgo');
});
