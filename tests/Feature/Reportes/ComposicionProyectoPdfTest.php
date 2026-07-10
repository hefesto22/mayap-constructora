<?php

declare(strict_types=1);

use App\Filament\Resources\Proyectos\Pages\ViewProyecto;
use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Models\User;
use App\Services\Reportes\ComposicionProyectoPdfService;
use App\Support\Permisos;
use App\Support\Roles;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| PDF "Composición del proyecto" — contenido y permisos.
|--------------------------------------------------------------------------
| El HTML se prueba sin Chromium (construirHtml). Los botones de la vista
| se muestran SOLO con su permiso personalizado (pestaña Personalizados).
*/

beforeEach(function (): void {
    foreach ([Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO, Permisos::DESCARGAR_PDF_COSTOS_PROYECTO, 'ViewAny:Proyecto', 'View:Proyecto'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }
});

test('el HTML de la composición incluye identificación, renglones agrupados y totales', function (): void {
    $cliente = Cliente::factory()->create(['nombre' => 'COMERCIAL HINDURENA', 'rtn' => '08019995123456']);
    $proyecto = Proyecto::factory()->create([
        'cliente_id'     => $cliente->id,
        'nombre'         => 'ALCANTARILLADO LAS PALMAS',
        'aplica_isv'     => true,
        'isv_porcentaje' => 15,
    ]);

    $ficha = Ficha::factory()->create(['zona_id' => $proyecto->zona_id, 'nombre' => 'LOSA DE CONCRETO E=10CM']);

    ProyectoRenglon::factory()->create([
        'proyecto_id'              => $proyecto->id,
        'ficha_id'                 => $ficha->id,
        'capitulo'                 => '01 PRELIMINARES',
        'cantidad'                 => '120.5000',
        'precio_unitario_snapshot' => '2604.37',
    ]);

    $html = app(ComposicionProyectoPdfService::class)->construirHtml($proyecto->fresh());

    expect($html)
        ->toContain('Composición del proyecto')
        ->toContain($proyecto->codigo)
        ->toContain('COMERCIAL HINDURENA')
        ->toContain('08019995123456')
        ->toContain('01 PRELIMINARES')
        ->toContain('LOSA DE CONCRETO E=10CM')
        // 120.5 × 2,604.37 = 313,826.59 (subtotal del renglón).
        ->toContain('313,826.59')
        ->toContain('ISV (15%)');
});

test('los botones de PDF aparecen SOLO con su permiso personalizado', function (): void {
    Role::firstOrCreate(['name' => Roles::ENCARGADO_OBRA, 'guard_name' => 'web']);

    $proyecto = Proyecto::factory()->enEjecucion()->create();

    // Usuario con acceso al proyecto pero SIN permisos de reportes.
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Roles::ENCARGADO_OBRA);
    $user->givePermissionTo(['ViewAny:Proyecto', 'View:Proyecto']);
    $proyecto->encargados()->attach($user->id);

    Livewire::actingAs($user)
        ->test(ViewProyecto::class, ['record' => $proyecto->getRouteKey()])
        ->assertActionHidden('pdf_composicion')
        ->assertActionHidden('pdf_costos');

    // Al otorgarle SOLO composición (desde la pantalla de Roles), gana
    // exactamente ese botón — el de costos (margen) sigue oculto.
    $user->givePermissionTo(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO);

    Livewire::actingAs($user->fresh())
        ->test(ViewProyecto::class, ['record' => $proyecto->getRouteKey()])
        ->assertActionVisible('pdf_composicion')
        ->assertActionHidden('pdf_costos');
});
