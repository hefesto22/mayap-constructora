<?php

declare(strict_types=1);

use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\User;
use App\Models\Zona;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| La LÓGICA de las acciones de ejecución (iniciar, anticipo, ajustar plazo,
| pausar, reactivar, finalizar, cancelar) se prueba a nivel de Service:
|   IniciarProyectoServiceTest, RegistrarAnticipoServiceTest,
|   AjustarPlazoProyectoServiceTest, CambiarEstadoEjecucionServiceTest.
| Acá solo verificamos que el listado renderiza con proyectos en ejecución.
| (Las acciones viven en el menú "Acciones" agrupado de la cabecera.)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(Utils::getSuperAdminName());

    Gate::before(fn ($user): ?bool => $user instanceof User && $user->hasRole(Utils::getSuperAdminName()) ? true : null);

    $this->actingAs($this->admin);

    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
});

test('el listado renderiza con proyectos en ejecución y pausados', function (): void {
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->enEjecucion()->create();
    Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->pausada()->create();

    Livewire::test(ListProyectos::class)->assertSuccessful();
});
