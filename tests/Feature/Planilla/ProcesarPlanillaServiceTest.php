<?php

declare(strict_types=1);

use App\Enums\EstadoPlanilla;
use App\Enums\TipoPago;
use App\Exceptions\Planilla\PlanillaNoEditableException;
use App\Models\Empleado;
use App\Models\Planilla;
use App\Models\PlanillaLinea;
use App\Models\Proyecto;
use App\Services\Planilla\ProcesarPlanillaService;
use App\Services\Reportes\CostoProyectoService;

/*
|--------------------------------------------------------------------------
| Golden tests del procesamiento de planilla y su impacto en el costo de obra.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = new ProcesarPlanillaService;
    $this->obra = Proyecto::factory()->create(['subtotal_cache' => 100000]);
    $this->planilla = Planilla::factory()->create();
});

test('recalcular calcula el monto por jornal: días × tarifa', function (): void {
    $empleado = Empleado::factory()->create(['tipo_pago' => TipoPago::Jornal->value]);
    PlanillaLinea::factory()->create([
        'planilla_id'     => $this->planilla->id,
        'empleado_id'     => $empleado->id,
        'tipo_pago'       => TipoPago::Jornal->value,
        'dias_trabajados' => 6,
        'tarifa_aplicada' => 500,
        'monto_bruto'     => 0,
    ]);

    $this->service->recalcular($this->planilla);

    // 6 × 500 = 3,000.
    expect($this->planilla->lineas()->first()->monto_bruto)->toBe('3000.00')
        ->and($this->planilla->fresh()->total_cache)->toBe('3000.00');
});

test('el salario fijo usa la tarifa como monto, sin importar días', function (): void {
    $empleado = Empleado::factory()->salario(5000)->create();
    PlanillaLinea::factory()->create([
        'planilla_id'     => $this->planilla->id,
        'empleado_id'     => $empleado->id,
        'tipo_pago'       => TipoPago::Salario->value,
        'dias_trabajados' => null,
        'tarifa_aplicada' => 5000,
        'monto_bruto'     => 0,
    ]);

    $this->service->recalcular($this->planilla);

    expect($this->planilla->lineas()->first()->monto_bruto)->toBe('5000.00');
});

test('el destajo respeta el monto capturado por tarea', function (): void {
    $empleado = Empleado::factory()->destajo()->create();
    PlanillaLinea::factory()->create([
        'planilla_id'     => $this->planilla->id,
        'empleado_id'     => $empleado->id,
        'tipo_pago'       => TipoPago::Destajo->value,
        'dias_trabajados' => null,
        'tarifa_aplicada' => 0,
        'descripcion'     => 'INSTALACIÓN DE 50 ML DE TUBERÍA',
        'monto_bruto'     => 4500,
    ]);

    $this->service->recalcular($this->planilla);

    expect($this->planilla->lineas()->first()->monto_bruto)->toBe('4500.00');
});

test('cerrar una planilla sin líneas es rechazado', function (): void {
    expect(fn () => $this->service->cerrar($this->planilla))
        ->toThrow(PlanillaNoEditableException::class);
});

test('no se puede cerrar una planilla ya cerrada', function (): void {
    $empleado = Empleado::factory()->create();
    PlanillaLinea::factory()->create([
        'planilla_id' => $this->planilla->id,
        'empleado_id' => $empleado->id,
    ]);
    $this->service->cerrar($this->planilla);

    expect(fn () => $this->service->cerrar($this->planilla->fresh()))
        ->toThrow(PlanillaNoEditableException::class);
});

test('GOLDEN: la mano de obra de una planilla cerrada entra al costo de la obra', function (): void {
    $empleado = Empleado::factory()->create(['tipo_pago' => TipoPago::Jornal->value]);
    PlanillaLinea::factory()->create([
        'planilla_id'     => $this->planilla->id,
        'empleado_id'     => $empleado->id,
        'proyecto_id'     => $this->obra->id,
        'tipo_pago'       => TipoPago::Jornal->value,
        'dias_trabajados' => 6,
        'tarifa_aplicada' => 500,
        'monto_bruto'     => 0,
    ]);

    $costoAntes = (new CostoProyectoService)->calcular($this->obra);
    expect($costoAntes->costoManoObra)->toBe('0.00'); // borrador no cuenta

    $this->service->cerrar($this->planilla);

    $costoDespues = (new CostoProyectoService)->calcular($this->obra);
    expect($costoDespues->costoManoObra)->toBe('3000.00')
        ->and($costoDespues->costoTotal)->toBe('3000.00')
        ->and($this->planilla->fresh()->estado)->toBe(EstadoPlanilla::Cerrada);
});
