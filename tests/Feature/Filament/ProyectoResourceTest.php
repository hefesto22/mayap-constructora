<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Filament\Resources\Proyectos\Pages\EditProyecto;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
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

    Livewire::test(ListProyectos::class)
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

    Livewire::test(ListProyectos::class)
        ->filterTable('zona_id', $this->zona->id)
        ->assertCanSeeTableRecords(Proyecto::deZona($this->zona->id)->get())
        ->assertCanNotSeeTableRecords(Proyecto::deZona($tgu->id)->get());
});

test('ProyectoResource: acción Recalcular actualiza el cache de totales', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('5', '1000.00')
        ->create();

    expect($proyecto->total_cache)->toBe('0.00');

    Livewire::test(EditProyecto::class, ['record' => $proyecto->id])
        ->callAction('recalcular');

    $proyecto->refresh();
    expect((float) $proyecto->total_cache)->toBeGreaterThan(0);
});

test('ProyectoResource: cambio de estado de borrador a enviada funciona', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    Livewire::test(EditProyecto::class, ['record' => $proyecto->id])
        ->callAction('cambiar_estado', data: [
            'nuevo_estado' => EstadoProyecto::Enviada->value,
            'razon'        => 'TEST',
        ]);

    expect($proyecto->fresh()->estado)->toBe(EstadoProyecto::Enviada);
});

test('ProyectoResource: acción Duplicar crea un proyecto nuevo en borrador', function (): void {
    $origen = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->create();

    expect(Proyecto::count())->toBe(1);

    Livewire::test(EditProyecto::class, ['record' => $origen->id])
        ->callAction('duplicar');

    expect(Proyecto::count())->toBe(2);
    expect(Proyecto::conEstado(EstadoProyecto::Borrador)->count())->toBe(1);
});
