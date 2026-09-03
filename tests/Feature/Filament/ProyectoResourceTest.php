<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Enums\TipoProyecto;
use App\Filament\Resources\Proyectos\Pages\CreateProyecto;
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

/*
|--------------------------------------------------------------------------
| Regresión 2026-09-03 — 500 al crear proyecto
|--------------------------------------------------------------------------
| "Call to a member function permiteEditar() on null" en ProyectoForm.
| handleRecordCreation() de Filament hace `new Proyecto($data); ->save()`
| y el form NO manda 'estado': Postgres ponía 'borrador' en la fila pero
| el modelo en memoria seguía sin el atributo, así que el re-render del
| mismo request Livewire encontraba $record !== null con estado null.
| Fix: default en memoria en $attributes + guards con ?-> en el form.
*/

test('ProyectoResource: crear proyecto no revienta por el estado sin cargar en memoria', function (): void {
    Livewire::test(CreateProyecto::class)
        ->fillForm([
            'tipo'           => TipoProyecto::Presupuestado->value,
            'zona_id'        => $this->zona->id,
            'cliente_id'     => $this->cliente->id,
            'nombre'         => 'RESIDENCIAL EL BAMBU',
            'direccion_obra' => 'SRC, BARRIO EL CENTRO',
            'fecha_emision'  => today()->toDateString(),
            'fecha_validez'  => today()->addDays(30)->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertSuccessful();

    $proyecto = Proyecto::query()->latest('id')->firstOrFail();

    expect($proyecto->nombre)->toBe('RESIDENCIAL EL BAMBU')
        ->and($proyecto->estado)->toBe(EstadoProyecto::Borrador);
});

/*
|--------------------------------------------------------------------------
| 2026-09-03 — listado menos saturado
|--------------------------------------------------------------------------
| Cliente pasó a ser la descripción de la columna Proyecto (la fila ya no
| crece a cuatro líneas). El buscador tiene que seguir encontrando por
| nombre de cliente, que era la razón de tenerlo como columna propia.
*/

test('ProyectoResource: el buscador encuentra por nombre de cliente', function (): void {
    $mio = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente(Cliente::factory()->create(['nombre' => 'FAMILIA MEJIA CASTRO']))
        ->create(['nombre' => 'RESIDENCIAL EL BAMBU']);

    $ajeno = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente(Cliente::factory()->create(['nombre' => 'GRUPO MEDICO OCCIDENTE']))
        ->create(['nombre' => 'CLINICA NORTE']);

    Livewire::test(ListProyectos::class, ['activeTab' => EstadoProyecto::Borrador->value])
        ->searchTable('MEJIA')
        ->assertCanSeeTableRecords([$mio])
        ->assertCanNotSeeTableRecords([$ajeno]);
});

test('ProyectoResource: el buscador sigue encontrando por nombre de obra', function (): void {
    $mio = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['nombre' => 'RESIDENCIAL EL BAMBU']);

    $ajeno = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['nombre' => 'CLINICA NORTE']);

    Livewire::test(ListProyectos::class, ['activeTab' => EstadoProyecto::Borrador->value])
        ->searchTable('BAMBU')
        ->assertCanSeeTableRecords([$mio])
        ->assertCanNotSeeTableRecords([$ajeno]);
});
