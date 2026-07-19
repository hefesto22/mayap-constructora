<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use App\Models\User;
use App\Services\Reportes\CotizacionRentaService;
use App\Support\Permisos;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Cotización de renta (imagen para WhatsApp + PDF): contenido del HTML,
| enlace wa.me y seguridad de las rutas. La conversión con Chromium no
| se prueba aquí (misma decisión que el resto de reportes).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(CotizacionRentaService::class);
});

function cotizacionDePrueba(): Proyecto
{
    $cliente = Cliente::factory()->create([
        'nombre'         => 'CONSTRUCTORA DEL VALLE',
        'telefono'       => '9999-8888',
        'condicion_pago' => 'credito',
        'dias_credito'   => 30,
    ]);

    $proyecto = Proyecto::factory()->renta()->paraCliente($cliente)->create([
        'subtotal_cache' => 7600,
        'isv_cache'      => 1140,
        'total_cache'    => 8740,
        'isv_porcentaje' => 15,
        'fecha_validez'  => today()->addDays(15),
    ]);

    $retro = Maquina::factory()->create(['nombre' => 'RETROEXCAVADORA JCB 3CX']);

    ProyectoLineaRenta::factory()->create([
        'proyecto_id'     => $proyecto->id,
        'maquina_id'      => $retro->id,
        'cantidad'        => '8.00',
        'tarifa_snapshot' => '950.00',
        'subtotal_cache'  => '7600.00',
    ]);

    ProyectoLineaRenta::factory()->extension()->create([
        'proyecto_id' => $proyecto->id,
        'orden'       => 2,
    ]);

    return $proyecto;
}

// ─── Contenido del documento ───────────────────────────────────────────

test('el HTML lleva cliente, máquinas, tarifas, totales y condiciones', function (): void {
    $proyecto = cotizacionDePrueba();

    $html = $this->service->construirHtml($proyecto);

    expect($html)->toContain('CONSTRUCTORA DEL VALLE')
        ->and($html)->toContain('RETROEXCAVADORA JCB 3CX')
        ->and($html)->toContain('L 950.00')
        ->and($html)->toContain('L 8,740.00')
        ->and($html)->toContain('Crédito a 30 días')
        ->and($html)->toContain('EXTENSIÓN')
        ->and($html)->toContain(today()->addDays(15)->format('d/m/Y'));
});

test('un cliente de contado muestra Contado', function (): void {
    $proyecto = Proyecto::factory()->renta()->paraCliente(
        Cliente::factory()->create(['condicion_pago' => 'contado']),
    )->create();

    expect(CotizacionRentaService::condicionPagoLabel($proyecto->load('cliente')))->toBe('Contado');
});

// ─── Enlace de WhatsApp ────────────────────────────────────────────────

test('el enlace de WhatsApp normaliza el teléfono hondureño y prellena el mensaje', function (): void {
    $proyecto = cotizacionDePrueba();

    $link = $this->service->linkWhatsApp($proyecto);

    expect($link)->toStartWith('https://wa.me/50499998888?text=')
        ->and($link)->toContain(rawurlencode($proyecto->codigo));
});

test('sin teléfono del cliente no hay enlace de WhatsApp', function (): void {
    $proyecto = Proyecto::factory()->renta()->paraCliente(
        Cliente::factory()->create(['telefono' => null]),
    )->create();

    expect($this->service->linkWhatsApp($proyecto))->toBeNull();
});

// ─── Seguridad de las rutas ────────────────────────────────────────────

test('las rutas de cotización exigen sesión iniciada', function (): void {
    $proyecto = Proyecto::factory()->renta()->create();

    get(route('reportes.cotizacion-renta', $proyecto))->assertRedirect();
    get(route('reportes.cotizacion-renta-imagen', $proyecto))->assertRedirect();
});

test('sin el permiso, la URL directa responde 403', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $proyecto = Proyecto::factory()->renta()->create();

    actingAs($user)->get(route('reportes.cotizacion-renta', $proyecto))->assertForbidden();
    actingAs($user)->get(route('reportes.cotizacion-renta-imagen', $proyecto))->assertForbidden();
});

test('un proyecto presupuestado responde 404 aunque se tenga permiso', function (): void {
    Permission::findOrCreate(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO, 'web');

    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO);

    $presupuestado = Proyecto::factory()->create();

    actingAs($user)->get(route('reportes.cotizacion-renta', $presupuestado))->assertNotFound();
    actingAs($user)->get(route('reportes.cotizacion-renta-imagen', $presupuestado))->assertNotFound();
});
