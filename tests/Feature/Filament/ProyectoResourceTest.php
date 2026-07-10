<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Filament\Resources\Proyectos\Pages\EditProyecto;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Models\Zona;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Tests Livewire/Filament del ProyectoResource (Sesión 3.3 del Sprint 3).
|--------------------------------------------------------------------------
| Cubre:
|  - Render sin error del listado, vacío y con datos.
|  - Tabs por estado funcionan (Borrador, Enviada, etc.).
|  - Filtros (zona, estado, cliente).
|  - Cambio de estado vía action.
|  - Recalcular precios individual.
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

    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->unidad = UnidadMedida::factory()->create();
    $this->ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create(['precio_venta_cache' => '1000.00']);
});

test('ProyectoResource: lista renderiza sin error sin proyectos', function (): void {
    Livewire::test(ListProyectos::class)
        ->assertSuccessful();
});

test('ProyectoResource: lista renderiza sin error con proyectos', function (): void {
    Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->count(3)
        ->create();

    // Las tabs filtran por estado (ya no existe "Todas"); los proyectos
    // de fábrica nacen en Borrador, así que se abre esa tab.
    Livewire::test(ListProyectos::class, ['activeTab' => EstadoProyecto::Borrador->value])
        ->assertSuccessful()
        ->assertCanSeeTableRecords(Proyecto::all());
});

test('ProyectoResource: tab por estado filtra correctamente', function (): void {
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->count(2)->create();
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->enviada()->count(3)->create();

    Livewire::test(ListProyectos::class, ['activeTab' => EstadoProyecto::Enviada->value])
        ->assertCanSeeTableRecords(Proyecto::conEstado(EstadoProyecto::Enviada)->get())
        ->assertCanNotSeeTableRecords(Proyecto::conEstado(EstadoProyecto::Borrador)->get());
});

test('ProyectoResource: filtro por zona funciona', function (): void {
    $tgu = Zona::factory()->create(['codigo' => 'TGU']);

    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->count(2)->create();
    Proyecto::factory()->enZona($tgu)->paraCliente($this->cliente)->count(3)->create();

    Livewire::test(ListProyectos::class, ['activeTab' => EstadoProyecto::Borrador->value])
        ->filterTable('zona_id', $this->zona->id)
        ->assertCanSeeTableRecords(Proyecto::deZona($this->zona->id)->get())
        ->assertCanNotSeeTableRecords(Proyecto::deZona($tgu->id)->get());
});

/*
| Las acciones de la cabecera (recalcular, cambiar estado, volver a
| borrador, duplicar, ejecución) ahora viven en el menú agrupado
| "Acciones". Filament no permite llamarlas por nombre con callAction
| cuando están agrupadas, así que su LÓGICA se cubre a nivel de Service:
|   TransicionComercialProyectoServiceTest (cambiar estado / volver a borrador),
|   DuplicarProyectoServiceTest, CalcularPrecioProyectoServiceTest, etc.
| Acá solo verificamos que la página de edición renderiza sin error
| (atrapa fallos de wiring de la cabecera/menú de acciones).
*/

test('ProyectoResource: la página de edición renderiza en borrador', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->create();

    Livewire::test(EditProyecto::class, ['record' => $proyecto->id])->assertSuccessful();
});

test('ProyectoResource: la página de edición renderiza en ejecución', function (): void {
    $proyecto = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->enEjecucion()->create();

    Livewire::test(EditProyecto::class, ['record' => $proyecto->id])->assertSuccessful();
});
