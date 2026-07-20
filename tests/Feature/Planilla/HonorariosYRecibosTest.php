<?php

declare(strict_types=1);

use App\Enums\TipoPago;
use App\Exceptions\Planilla\PlanillaNoEditableException;
use App\Models\Empleado;
use App\Models\Planilla;
use App\Models\PlanillaLinea;
use App\Models\User;
use App\Services\Planilla\ProcesarPlanillaService;
use App\Services\Planilla\ReciboPagoService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Golden tests de honorarios (retención 12.5%), deducciones/neto y los
| recibos de pago (pedido del cliente 2026-07-20).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(ProcesarPlanillaService::class);
});

/**
 * @param array<string, mixed> $linea
 */
function lineaDePlanilla(array $linea = []): PlanillaLinea
{
    return PlanillaLinea::factory()->create([
        'planilla_id' => Planilla::factory()->create()->id,
        ...$linea,
    ]);
}

// ─── Honorarios: retención 12.5% ───────────────────────────────────────

test('honorarios retiene el 12.5% por defecto y calcula el neto', function (): void {
    $linea = lineaDePlanilla([
        'tipo_pago'       => TipoPago::Honorarios->value,
        'dias_trabajados' => null,
        'tarifa_aplicada' => 20000,
        'monto_bruto'     => 0,
    ]);

    $this->service->recalcular($linea->planilla);

    $linea->refresh();

    expect($linea->monto_bruto)->toBe('20000.00')
        ->and($linea->retencion_porcentaje)->toBe('12.50')
        ->and($linea->retencion_monto)->toBe('2500.00')
        ->and($linea->monto_neto)->toBe('17500.00');
});

test('la retención capturada a mano manda sobre la sugerida', function (): void {
    $linea = lineaDePlanilla([
        'tipo_pago'            => TipoPago::Honorarios->value,
        'tarifa_aplicada'      => 20000,
        'retencion_porcentaje' => 10,
        'monto_bruto'          => 0,
    ]);

    $this->service->recalcular($linea->planilla);

    expect($linea->refresh()->retencion_monto)->toBe('2000.00')
        ->and($linea->monto_neto)->toBe('18000.00');
});

test('el jornal no retiene nada: neto = bruto − deducciones', function (): void {
    $linea = lineaDePlanilla([
        'tipo_pago'       => TipoPago::Jornal->value,
        'dias_trabajados' => 5,
        'tarifa_aplicada' => 600,
        'deducciones'     => 500,
    ]);

    $this->service->recalcular($linea->planilla);

    $linea->refresh();

    expect($linea->monto_bruto)->toBe('3000.00')
        ->and($linea->retencion_monto)->toBe('0.00')
        ->and($linea->monto_neto)->toBe('2500.00');
});

test('deducciones que dejan el neto negativo revientan con mensaje claro', function (): void {
    $linea = lineaDePlanilla([
        'tipo_pago'       => TipoPago::Jornal->value,
        'dias_trabajados' => 1,
        'tarifa_aplicada' => 600,
        'deducciones'     => 900,
    ]);

    expect(fn () => $this->service->recalcular($linea->planilla))
        ->toThrow(PlanillaNoEditableException::class, 'neto');
});

test('el total de la planilla sigue siendo la suma de BRUTOS (costo real)', function (): void {
    $planilla = Planilla::factory()->create();

    PlanillaLinea::factory()->create([
        'planilla_id'     => $planilla->id,
        'tipo_pago'       => TipoPago::Honorarios->value,
        'tarifa_aplicada' => 10000,
        'monto_bruto'     => 0,
    ]);
    PlanillaLinea::factory()->create([
        'planilla_id'     => $planilla->id,
        'tipo_pago'       => TipoPago::Jornal->value,
        'dias_trabajados' => 5,
        'tarifa_aplicada' => 600,
    ]);

    $this->service->recalcular($planilla);

    // 10,000 + 3,000 brutos — la retención NO reduce el costo de la obra.
    expect($planilla->refresh()->total_cache)->toBe('13000.00');
});

// ─── Frecuencia de pago por empleado ───────────────────────────────────

test('el filtro por frecuencia solo trae empleados de esa periodicidad', function (): void {
    Empleado::factory()->create(['periodicidad_pago' => 'quincenal']);
    Empleado::factory()->create(['periodicidad_pago' => 'mensual']);

    expect(Empleado::query()->dePeriodicidad('mensual')->count())->toBe(1)
        ->and(Empleado::query()->dePeriodicidad('quincenal')->count())->toBe(1)
        ->and(Empleado::query()->dePeriodicidad(null)->count())->toBe(2);
});

test('un empleado nuevo nace quincenal por defecto', function (): void {
    $empleado = Empleado::factory()->create();

    expect($empleado->refresh()->periodicidad_pago->value)->toBe('quincenal');
});

// ─── Recibos de pago ───────────────────────────────────────────────────

test('el HTML del recibo lleva empleado, retención, deducciones y neto', function (): void {
    $empleado = Empleado::factory()->create(['nombre' => 'ING. PEDRO ZELAYA']);

    $linea = lineaDePlanilla([
        'empleado_id'     => $empleado->id,
        'tipo_pago'       => TipoPago::Honorarios->value,
        'tarifa_aplicada' => 20000,
        'deducciones'     => 1000,
        'monto_bruto'     => 0,
    ]);

    app(ProcesarPlanillaService::class)->cerrar($linea->planilla);

    $html = app(ReciboPagoService::class)->construirHtml($linea->planilla->refresh());

    expect($html)->toContain('RECIBO DE PAGO')
        ->and($html)->toContain('ING. PEDRO ZELAYA')
        ->and($html)->toContain('Retención ISR (12.5%)')
        ->and($html)->toContain('2,500.00')
        ->and($html)->toContain('Deducciones')
        ->and($html)->toContain('16,500.00')
        ->and($html)->toContain('Recibí conforme');
});

test('la ruta de recibos exige sesión, permiso y planilla cerrada', function (): void {
    $linea = lineaDePlanilla();
    $planilla = $linea->planilla;

    // Sin sesión → login.
    get(route('reportes.recibos-planilla', $planilla))->assertRedirect();

    // Con sesión pero sin permiso → 403.
    $user = User::factory()->create(['is_active' => true]);
    actingAs($user)->get(route('reportes.recibos-planilla', $planilla))->assertForbidden();

    // Con permiso pero planilla en borrador → 404 (el recibo es del pago
    // confirmado).
    Permission::findOrCreate('View:Planilla', 'web');
    $user->givePermissionTo('View:Planilla');

    actingAs($user)->get(route('reportes.recibos-planilla', $planilla))->assertNotFound();
});
