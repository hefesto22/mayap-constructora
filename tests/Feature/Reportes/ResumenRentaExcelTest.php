<?php

declare(strict_types=1);

use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use App\Models\User;
use App\Services\Reportes\ResumenRentaService;
use App\Support\Permisos;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Resumen "pactado vs real" de la maquinaria rentada (Excel gerencial,
| decisión Mauricio 2026-07-20). Mismas reglas de cobro que
| FinalizarRentaService: lo pactado es el mínimo; el extra sale del
| trabajo real (horas, viajes o km) que lo supera.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(ResumenRentaService::class);
});

/**
 * Renta con una retro por 1 DÍA (jornada 8h, L 7,600/día → L 950/hora)
 * y partes de trabajo por 10 horas reales: 2 de exceso.
 */
function rentaConPartes(): Proyecto
{
    $proyecto = Proyecto::factory()->renta()->create();

    $retro = Maquina::factory()->create([
        'nombre'        => 'RETROEXCAVADORA JCB 3CX',
        'jornada_horas' => 8,
    ]);

    ProyectoLineaRenta::factory()->porDia()->create([
        'proyecto_id'     => $proyecto->id,
        'maquina_id'      => $retro->id,
        'cantidad'        => '1.00',
        'tarifa_snapshot' => '7600.00',
        'subtotal_cache'  => '7600.00',
    ]);

    $asignacion = AsignacionMaquina::factory()->create([
        'proyecto_id' => $proyecto->id,
        'maquina_id'  => $retro->id,
    ]);

    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacion->id,
        'horas'                 => 9,
        'horas_extra'           => 1,
        // CHECK en DB: horas extra exigen su motivo.
        'motivo_horas_extra' => 'CLIENTE PIDIO TERMINAR LA ZANJA',
    ]);

    return $proyecto;
}

test('el resumen compara pactado vs real por máquina con el extra facturable', function (): void {
    $resumen = $this->service->resumen(rentaConPartes());

    expect($resumen['filas'])->toHaveCount(1);

    $fila = $resumen['filas'][0];

    // 1 día × 8h de jornada = 8 pactadas; partes 9 + 1 = 10 reales.
    expect($fila['maquina'])->toContain('RETROEXCAVADORA JCB 3CX')
        ->and($fila['unidad'])->toBe('Horas')
        ->and($fila['pactado_cant'])->toBe('8.00')
        ->and($fila['real_cant'])->toBe('10.00')
        ->and($fila['diferencia'])->toBe('2.00')
        // Tarifa hora = 7,600 / 8 = 950 → extra = 2 × 950.
        ->and($fila['tarifa'])->toBe('950.00')
        ->and($fila['extra'])->toBe('1900.00')
        ->and($resumen['total_pactado'])->toBe('7600.00')
        ->and($resumen['total_extra'])->toBe('1900.00');
});

test('trabajar menos de lo pactado no genera extra (el mínimo es lo cotizado)', function (): void {
    $proyecto = Proyecto::factory()->renta()->create();

    $maquina = Maquina::factory()->create(['jornada_horas' => 8]);

    ProyectoLineaRenta::factory()->create([
        'proyecto_id'     => $proyecto->id,
        'maquina_id'      => $maquina->id,
        'cantidad'        => '8.00',
        'tarifa_snapshot' => '950.00',
        'subtotal_cache'  => '7600.00',
    ]);

    // Solo 5 horas reales: diferencia negativa, extra CERO.
    $asignacion = AsignacionMaquina::factory()->create([
        'proyecto_id' => $proyecto->id,
        'maquina_id'  => $maquina->id,
    ]);

    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacion->id,
        'horas'                 => 5,
        'horas_extra'           => 0,
    ]);

    $fila = $this->service->resumen($proyecto)['filas'][0];

    expect($fila['diferencia'])->toBe('-3.00')
        ->and($fila['extra'])->toBe('0.00');
});

test('la ruta del Excel exige sesión, permiso y proyecto de renta', function (): void {
    $proyecto = rentaConPartes();

    // Sin sesión → login.
    get(route('reportes.resumen-renta-excel', $proyecto))->assertRedirect();

    // Con sesión pero sin permiso → 403.
    $user = User::factory()->create(['is_active' => true]);
    actingAs($user)->get(route('reportes.resumen-renta-excel', $proyecto))->assertForbidden();

    // Con permiso → descarga el .xlsx.
    Permission::findOrCreate(Permisos::DESCARGAR_PDF_COSTOS_PROYECTO, 'web');
    $user->givePermissionTo(Permisos::DESCARGAR_PDF_COSTOS_PROYECTO);

    actingAs($user)->get(route('reportes.resumen-renta-excel', $proyecto))
        ->assertOk()
        ->assertDownload('resumen-renta-'.$proyecto->codigo.'.xlsx');

    // Un proyecto que NO es renta → 404 aunque haya permiso.
    $presupuestado = Proyecto::factory()->create();
    actingAs($user)->get(route('reportes.resumen-renta-excel', $presupuestado))->assertNotFound();
});
