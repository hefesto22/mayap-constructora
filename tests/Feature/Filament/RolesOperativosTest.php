<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Filament\Resources\Requisiciones\RequisicionResource;
use App\Filament\Widgets\MiBandejaWidget;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\User;
use App\Support\Permisos;
use App\Support\Roles;
use Database\Seeders\RolesInventarioSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Fase G1 — Roles operativos, scoping por encargado y Mi Bandeja.
|--------------------------------------------------------------------------
*/

function crearPermisosShieldG1(): void
{
    $permisos = [
        'ViewAny:Compra', 'View:Compra', 'Create:Compra', 'Update:Compra', 'Delete:Compra',
        'ViewAny:Proveedor', 'View:Proveedor', 'Create:Proveedor',
        'ViewAny:CuentaPorPagar', 'View:CuentaPorPagar', 'Create:CuentaPorPagar',
        'ViewAny:Requisicion', 'View:Requisicion', 'Create:Requisicion', 'Update:Requisicion',
        'ViewAny:Material', 'View:Material', 'Create:Material',
        'ViewAny:Bodega', 'View:Bodega',
        'ViewAny:Existencia', 'View:Existencia',
        'ViewAny:Maquina', 'View:Maquina', 'Create:Maquina', 'Update:Maquina',
        'ViewAny:AsignacionMaquina', 'View:AsignacionMaquina', 'Create:AsignacionMaquina',
        'ViewAny:ParteTrabajo', 'View:ParteTrabajo', 'Create:ParteTrabajo',
        'ViewAny:ConsumoCombustible', 'Create:ConsumoCombustible',
        'ViewAny:MantenimientoMaquina', 'Create:MantenimientoMaquina',
        'ViewAny:Proyecto', 'View:Proyecto', 'Create:Proyecto', 'Update:Proyecto',
    ];

    foreach ($permisos as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }
}

test('el seeder crea los roles operativos con el alcance correcto', function (): void {
    crearPermisosShieldG1();

    (new RolesInventarioSeeder)->run();

    $recepcion = Role::findByName(Roles::RECEPCION, 'web');
    $maquinaria = Role::findByName(Roles::MAQUINARIA, 'web');
    $encargado = Role::findByName(Roles::ENCARGADO_OBRA, 'web');

    // Recepción compra, pero NO despacha requisiciones ni edita catálogo.
    expect($recepcion->hasPermissionTo('Create:Compra', 'web'))->toBeTrue()
        ->and($recepcion->hasPermissionTo('ViewAny:Requisicion', 'web'))->toBeTrue()
        ->and($recepcion->hasPermissionTo('Update:Requisicion', 'web'))->toBeFalse()
        ->and($recepcion->hasPermissionTo('Create:Material', 'web'))->toBeFalse();

    // Maquinaria administra el parque, proyectos solo lectura.
    expect($maquinaria->hasPermissionTo('Create:Maquina', 'web'))->toBeTrue()
        ->and($maquinaria->hasPermissionTo('Create:ParteTrabajo', 'web'))->toBeTrue()
        ->and($maquinaria->hasPermissionTo('View:Proyecto', 'web'))->toBeTrue()
        ->and($maquinaria->hasPermissionTo('Create:Proyecto', 'web'))->toBeFalse();

    // Encargado pide material pero no compra, no administra máquinas y
    // NO ve catálogos/existencias (su ventana es la requisición).
    expect($encargado->hasPermissionTo('Create:Requisicion', 'web'))->toBeTrue()
        ->and($encargado->hasPermissionTo('Create:Compra', 'web'))->toBeFalse()
        ->and($encargado->hasPermissionTo('ViewAny:Material', 'web'))->toBeFalse()
        ->and($encargado->hasPermissionTo('ViewAny:Existencia', 'web'))->toBeFalse();

    // Permisos GRANULARES de ejecución: gerencia todos; encargado solo lo
    // operativo de campo (pausar/reactivar), nada contractual.
    $gerencia = Role::findByName(Roles::GERENCIA, 'web');

    expect($gerencia->hasPermissionTo('Pausar:Proyecto', 'web'))->toBeTrue()
        ->and($gerencia->hasPermissionTo('Cancelar:Proyecto', 'web'))->toBeTrue()
        ->and($gerencia->hasPermissionTo('RegistrarAnticipo:Proyecto', 'web'))->toBeTrue()
        ->and($encargado->hasPermissionTo('Pausar:Proyecto', 'web'))->toBeTrue()
        ->and($encargado->hasPermissionTo('Reactivar:Proyecto', 'web'))->toBeTrue()
        ->and($encargado->hasPermissionTo('Finalizar:Proyecto', 'web'))->toBeFalse()
        ->and($encargado->hasPermissionTo('Cancelar:Proyecto', 'web'))->toBeFalse()
        ->and($encargado->hasPermissionTo('RegistrarAnticipo:Proyecto', 'web'))->toBeFalse();

    // Anular compras: SOLO gerencia (recepción no se auto-anula errores).
    expect($gerencia->hasPermissionTo(Permisos::ANULAR_COMPRA, 'web'))->toBeTrue()
        ->and($recepcion->hasPermissionTo(Permisos::ANULAR_COMPRA, 'web'))->toBeFalse();

    // Visibilidad por estado: gerencia ve los 7; encargado solo vivas.
    expect($gerencia->hasPermissionTo(Permisos::VER_CANCELADAS_PROYECTO, 'web'))->toBeTrue()
        ->and($gerencia->hasPermissionTo(Permisos::VER_BORRADORES_PROYECTO, 'web'))->toBeTrue()
        ->and($encargado->hasPermissionTo(Permisos::VER_CANCELADAS_PROYECTO, 'web'))->toBeFalse()
        ->and($encargado->hasPermissionTo(Permisos::VER_BORRADORES_PROYECTO, 'web'))->toBeFalse();
});

test('las tabs de proyectos aparecen estado por estado según el permiso otorgado', function (): void {
    crearPermisosShieldG1();
    (new RolesInventarioSeeder)->run();

    $encargado = User::factory()->create(['is_active' => true]);
    $encargado->assignRole(Roles::ENCARGADO_OBRA);

    $miObra = Proyecto::factory()->enEjecucion()->create();
    $miObra->encargados()->attach($encargado->id);
    Proyecto::factory()->enEjecucion()->create(); // ajena
    Proyecto::factory()->create();                // borrador (comercial)

    // Encargado: SOLO las tabs vivas, y el conteo respeta su scoping.
    $this->actingAs($encargado);
    $tabs = (new ListProyectos)->getTabs();

    expect(array_keys($tabs))->toBe([
        EstadoProyecto::EnEjecucion->value,
        EstadoProyecto::Pausada->value,
    ])->and((int) $tabs[EstadoProyecto::EnEjecucion->value]->getBadge())->toBe(1);

    // GRANULAR: al otorgarle SOLO "Ver cancelados" (desde la pantalla de
    // Roles), gana exactamente esa tab — nada más del ciclo comercial.
    $encargado->givePermissionTo(Permisos::VER_CANCELADAS_PROYECTO);
    $encargado->unsetRelation('permissions');

    expect(array_keys((new ListProyectos)->getTabs()))->toBe([
        EstadoProyecto::EnEjecucion->value,
        EstadoProyecto::Pausada->value,
        EstadoProyecto::Cancelada->value,
    ]);

    // Gerencia (los 7 permisos): ciclo completo, 9 tabs.
    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->assignRole(Roles::GERENCIA);
    $this->actingAs($gerente);

    expect((new ListProyectos)->getTabs())->toHaveCount(9)
        ->and(array_keys((new ListProyectos)->getTabs()))->toContain(EstadoProyecto::Borrador->value);
});

test('el encargado de obra solo ve SUS obras y SUS requisiciones', function (): void {
    Role::firstOrCreate(['name' => Roles::ENCARGADO_OBRA, 'guard_name' => 'web']);

    $encargado = User::factory()->create(['is_active' => true]);
    $encargado->assignRole(Roles::ENCARGADO_OBRA);
    $otro = User::factory()->create(['is_active' => true]);

    // El encargado solo ve obras VIVAS (en ejecución/pausadas).
    $miObra = Proyecto::factory()->enEjecucion()->create();
    $miObra->encargados()->attach($encargado->id);

    $obraAjena = Proyecto::factory()->enEjecucion()->create();
    $obraAjena->encargados()->attach($otro->id);

    // Suya pero en borrador (cotización): tampoco la ve.
    $miCotizacion = Proyecto::factory()->create();
    $miCotizacion->encargados()->attach($encargado->id);

    $miReq = Requisicion::factory()->paraProyecto($miObra)->create();
    $reqAjena = Requisicion::factory()->paraProyecto($obraAjena)->create();

    $this->actingAs($encargado);

    $proyectosVisibles = ProyectoResource::getEloquentQuery()->pluck('id');
    $requisicionesVisibles = RequisicionResource::getEloquentQuery()->pluck('id');

    expect($proyectosVisibles)->toContain($miObra->id)
        ->and($proyectosVisibles)->not->toContain($obraAjena->id)
        ->and($proyectosVisibles)->not->toContain($miCotizacion->id)
        ->and($requisicionesVisibles)->toContain($miReq->id)
        ->and($requisicionesVisibles)->not->toContain($reqAjena->id);
});

test('una obra puede tener VARIOS encargados y todos la ven', function (): void {
    Role::firstOrCreate(['name' => Roles::ENCARGADO_OBRA, 'guard_name' => 'web']);

    $titular = User::factory()->create(['is_active' => true]);
    $suplente = User::factory()->create(['is_active' => true]);
    $titular->assignRole(Roles::ENCARGADO_OBRA);
    $suplente->assignRole(Roles::ENCARGADO_OBRA);

    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach([$titular->id, $suplente->id]);

    $this->actingAs($suplente);

    expect(ProyectoResource::getEloquentQuery()->pluck('id'))->toContain($obra->id)
        ->and($obra->esEncargado($suplente))->toBeTrue()
        ->and($obra->esEncargado(User::factory()->create()))->toBeFalse();
});

test('bodeguero y gerencia ven TODAS las requisiciones (sin scoping)', function (): void {
    Role::firstOrCreate(['name' => Roles::BODEGUERO, 'guard_name' => 'web']);

    $bodeguero = User::factory()->create(['is_active' => true]);
    $bodeguero->assignRole(Roles::BODEGUERO);

    $obraA = Proyecto::factory()->create();
    $obraB = Proyecto::factory()->create();
    Requisicion::factory()->paraProyecto($obraA)->create();
    Requisicion::factory()->paraProyecto($obraB)->create();

    $this->actingAs($bodeguero);

    expect(RequisicionResource::getEloquentQuery()->count())->toBe(2);
});

test('TODOS los roles operativos pueden entrar al panel', function (): void {
    $panel = Filament::getDefaultPanel();

    foreach (Roles::OPERATIVOS as $rol) {
        Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($rol);

        expect($user->canAccessPanel($panel))->toBeTrue("El rol {$rol} no puede entrar al panel.");
    }

    // Sin rol → sin acceso.
    $sinRol = User::factory()->create(['is_active' => true]);
    expect($sinRol->canAccessPanel($panel))->toBeFalse();
});

test('Mi Bandeja muestra los pendientes según el rol', function (): void {
    Role::firstOrCreate(['name' => Roles::BODEGUERO, 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Roles::RECEPCION, 'guard_name' => 'web']);

    $bodeguero = User::factory()->create(['is_active' => true]);
    $bodeguero->assignRole(Roles::BODEGUERO);

    Requisicion::factory()->create(); // Solicitada (default)

    Livewire::actingAs($bodeguero)
        ->test(MiBandejaWidget::class)
        ->assertSuccessful()
        ->assertSee('Requisiciones por autorizar');

    // Recepción NO ve la bandeja de bodega, sí la de compras.
    $recepcion = User::factory()->create(['is_active' => true]);
    $recepcion->assignRole(Roles::RECEPCION);

    Livewire::actingAs($recepcion)
        ->test(MiBandejaWidget::class)
        ->assertSuccessful()
        ->assertSee('Compras pendientes')
        ->assertDontSee('Requisiciones por autorizar');
});
