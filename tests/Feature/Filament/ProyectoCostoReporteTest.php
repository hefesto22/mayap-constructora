<?php

declare(strict_types=1);

use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Filament\Resources\Proyectos\Pages\ViewProyecto;
use App\Models\Bodega;
use App\Models\Item;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\RegistrarParteService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

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

test('el listado de proyectos renderiza con la columna de margen', function (): void {
    Proyecto::factory()->count(3)->create(['subtotal_cache' => 50000]);

    Livewire::test(ListProyectos::class)->assertSuccessful();
});

test('la vista de la obra muestra el desglose de costo real', function (): void {
    $obra = Proyecto::factory()->create(['subtotal_cache' => 100000]);

    // Materiales: despacha 40 u a L.25 = 1,000.
    $bodega = Bodega::factory()->create();
    $item = Item::factory()->create();
    $inventario = new RegistrarMovimientoService;
    $inventario->entradaCompra(
        itemId: $item->id,
        destino: Ubicacion::bodega($bodega->id),
        cantidad: '40',
        costoUnitario: '25',
    );
    $inventario->salidaDespacho(
        itemId: $item->id,
        origen: Ubicacion::bodega($bodega->id),
        destino: Ubicacion::obra($obra->id),
        cantidad: '40',
    );

    // Maquinaria: parte 5 h × 2,000 = 10,000.
    $maquina = Maquina::factory()->create(['jornada_horas' => 8, 'horometro_actual' => 0]);
    $asignacion = (new AsignarMaquinaService)->asignar($maquina, $obra->id, tarifaPactada: '2000');
    (new RegistrarParteService)->registrarManual($asignacion, horas: '5');

    // Costo total 11,000; margen 89,000 (89%). El porcentaje lo formatea
    // bcmath ("89.00"), independiente del locale de money().
    Livewire::test(ViewProyecto::class, ['record' => $obra->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Costo real')
        ->assertSee('89.00');
});
