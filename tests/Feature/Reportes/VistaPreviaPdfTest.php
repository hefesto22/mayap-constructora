<?php

declare(strict_types=1);

use App\Models\Bodega;
use App\Models\Compra;
use App\Models\Proyecto;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Vista previa de PDFs — autorización de las rutas.
|--------------------------------------------------------------------------
| Los PDFs se sirven inline por URL; estas pruebas cubren la seguridad de
| la ruta (defense in depth: el permiso se re-valida en el servidor, no
| solo en la visibilidad del botón). La generación en sí ya está cubierta
| por los tests de construirHtml de cada servicio.
*/

test('las rutas de vista previa exigen sesión iniciada', function (): void {
    $compra = Compra::factory()->paraBodega(Bodega::factory()->create())->create();
    $proyecto = Proyecto::factory()->create();

    get(route('reportes.acta-recepcion', $compra))->assertRedirect();
    get(route('reportes.costo-obra', $proyecto))->assertRedirect();
    get(route('reportes.composicion-proyecto', $proyecto))->assertRedirect();
});

test('sin el permiso, la URL directa responde 403 aunque se conozca el enlace', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $compra = Compra::factory()->paraBodega(Bodega::factory()->create())->create();
    $proyecto = Proyecto::factory()->create();

    actingAs($user)->get(route('reportes.acta-recepcion', $compra))->assertForbidden();
    actingAs($user)->get(route('reportes.costo-obra', $proyecto))->assertForbidden();
    actingAs($user)->get(route('reportes.composicion-proyecto', $proyecto))->assertForbidden();
});

test('el acta de una compra sin verificación responde 404 aunque se tenga permiso', function (): void {
    Permission::findOrCreate('View:Compra', 'web');

    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo('View:Compra');

    // En Borrador: nadie ha contado bultos — el acta todavía no existe.
    $compra = Compra::factory()->paraBodega(Bodega::factory()->create())->create();

    actingAs($user)->get(route('reportes.acta-recepcion', $compra))->assertNotFound();
});
