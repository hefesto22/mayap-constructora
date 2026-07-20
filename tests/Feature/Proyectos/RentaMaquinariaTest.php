<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Enums\TipoProyecto;
use App\Enums\UnidadRenta;
use App\Exceptions\Proyectos\RentaInvalidaException;
use App\Models\AsignacionMaquina;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
use App\Models\Proyecto;
use App\Services\Cobranza\CobrarService;
use App\Services\Proyectos\AgregarLineaRentaService;
use App\Services\Proyectos\AprobarRentaService;
use App\Services\Proyectos\ExtenderRentaService;
use App\Services\Proyectos\FinalizarRentaService;

/*
|--------------------------------------------------------------------------
| Golden tests del ciclo de RENTA DE MAQUINARIA: cotizar líneas, aprobar
| (agenda + CxC según condición del cliente), extender y finalizar con
| el extra de horas reales sobre lo pactado.
|--------------------------------------------------------------------------
*/

/**
 * Renta en borrador con UNA línea: 8 horas × L 950 = L 7,600
 * (+15% ISV = L 8,740). La máquina llega pasado mañana a las 7:00.
 */
function crearRentaConLinea(?Cliente $cliente = null): Proyecto
{
    $proyecto = Proyecto::factory()
        ->renta()
        ->for($cliente ?? Cliente::factory()->create())
        ->create();

    $maquina = Maquina::factory()->create(['tarifa_hora' => 950, 'jornada_horas' => 8]);

    app(AgregarLineaRentaService::class)->agregar(
        $proyecto,
        $maquina->id,
        UnidadRenta::Hora,
        '8',
        now()->addDays(2)->toDateString(),
        '07:00',
    );

    return $proyecto->refresh();
}

test('una línea de renta calcula subtotal y totales con ISV', function (): void {
    $proyecto = crearRentaConLinea();

    $linea = $proyecto->lineasRenta->first();

    expect($linea->subtotal_cache)->toBe('7600.00')
        ->and($linea->tarifa_snapshot)->toBe('950.00')
        ->and($proyecto->subtotal_cache)->toBe('7600.00')
        ->and($proyecto->isv_cache)->toBe('1140.00')
        ->and($proyecto->total_cache)->toBe('8740.00');
});

test('la tarifa se sugiere del catálogo según la unidad', function (): void {
    $proyecto = Proyecto::factory()->renta()->create();
    $maquina = Maquina::factory()->create(['tarifa_hora' => 1000, 'jornada_horas' => 8]);

    $porDia = app(AgregarLineaRentaService::class)->agregar(
        $proyecto,
        $maquina->id,
        UnidadRenta::Dia,
        '2',
        now()->addDays(2)->toDateString(),
    );

    // Día = tarifa_hora × jornada: 1000 × 8 = 8000. Dos días = 16,000.
    expect($porDia->tarifa_snapshot)->toBe('8000.00')
        ->and($porDia->subtotal_cache)->toBe('16000.00');
});

test('aprobar una renta la deja en ejecución, agendada y con cuenta por cobrar', function (): void {
    $proyecto = crearRentaConLinea();
    $linea = $proyecto->lineasRenta->first();

    $resultado = app(AprobarRentaService::class)->aprobar($proyecto);

    $proyecto->refresh();

    // Auto-inicio: aprobada = va (fecha_inicio = primera llegada).
    expect($proyecto->estado)->toBe(EstadoProyecto::EnEjecucion)
        ->and($proyecto->fecha_inicio->toDateString())->toBe($linea->fecha_llegada->toDateString());

    // Agendada en el calendario con su hora de llegada.
    $this->assertDatabaseHas('agenda_maquina', [
        'maquina_id'  => $linea->maquina_id,
        'proyecto_id' => $proyecto->id,
        'fecha'       => $linea->fecha_llegada->toDateString(),
    ]);

    // La deuda nace al aprobar, por el total cotizado.
    $cuenta = $resultado['cuenta'];
    expect($cuenta)->toBeInstanceOf(CuentaPorCobrar::class)
        ->and($cuenta->monto_original)->toBe('8740.00')
        ->and($cuenta->saldo)->toBe('8740.00')
        ->and($cuenta->proyecto_id)->toBe($proyecto->id);
});

test('cliente al contado vence el mismo día; a crédito según sus días', function (): void {
    $contado = crearRentaConLinea();
    $cuentaContado = app(AprobarRentaService::class)->aprobar($contado)['cuenta'];

    expect($cuentaContado->fecha_vencimiento->toDateString())->toBe(today()->toDateString());

    $credito = crearRentaConLinea(Cliente::factory()->aCredito(30)->create());
    $cuentaCredito = app(AprobarRentaService::class)->aprobar($credito)['cuenta'];

    expect($cuentaCredito->fecha_vencimiento->toDateString())
        ->toBe(today()->addDays(30)->toDateString());
});

test('aprobar una renta sin líneas es rechazado', function (): void {
    $proyecto = Proyecto::factory()->renta()->create();

    expect(fn () => app(AprobarRentaService::class)->aprobar($proyecto))
        ->toThrow(RentaInvalidaException::class);
});

test('aprobar renta solo aplica a proyectos tipo renta', function (): void {
    $presupuestado = Proyecto::factory()->create();

    expect(fn () => app(AprobarRentaService::class)->aprobar($presupuestado))
        ->toThrow(RentaInvalidaException::class);

    expect($presupuestado->refresh()->tipo)->toBe(TipoProyecto::Presupuestado);
});

test('extender una renta agrega línea de extensión, sube el total y la cuenta', function (): void {
    $proyecto = crearRentaConLinea();
    $maquina = $proyecto->lineasRenta->first()->maquina;

    app(AprobarRentaService::class)->aprobar($proyecto);
    $proyecto->refresh();

    $resultado = app(ExtenderRentaService::class)->extender(
        $proyecto,
        $maquina->id,
        UnidadRenta::Hora,
        '4',
        now()->addDays(3)->toDateString(),
        '07:00',
    );

    $linea = $resultado['linea'];
    $proyecto->refresh();

    // La extensión queda marcada y NUNCA toca la línea original.
    expect($linea->es_extension)->toBeTrue()
        ->and($proyecto->lineasRenta)->toHaveCount(2)
        // Totales: 7,600 + 3,800 = 11,400 · ISV 1,710 · total 13,110.
        ->and($proyecto->total_cache)->toBe('13110.00');

    // La CxC subió el valor extendido CON ISV: 3,800 × 1.15 = 4,370.
    $cuenta = CuentaPorCobrar::where('proyecto_id', $proyecto->id)->first();
    expect($cuenta->monto_original)->toBe('13110.00')
        ->and($cuenta->saldo)->toBe('13110.00');
});

test('extender exige un estado vivo', function (): void {
    $proyecto = crearRentaConLinea();
    $maquina = $proyecto->lineasRenta->first()->maquina;

    // En Borrador no se extiende: se editan las líneas directamente.
    expect(fn () => app(ExtenderRentaService::class)->extender(
        $proyecto,
        $maquina->id,
        UnidadRenta::Hora,
        '4',
        now()->addDays(3)->toDateString(),
    ))->toThrow(RentaInvalidaException::class);
});

test('finalizar con horas reales sobre lo pactado cobra el extra con ISV', function (): void {
    $proyecto = crearRentaConLinea();
    $linea = $proyecto->lineasRenta->first();

    app(AprobarRentaService::class)->aprobar($proyecto);
    $proyecto->refresh();

    // El parte dice que trabajó 10 horas (pactadas: 8).
    $asignacion = AsignacionMaquina::factory()->create([
        'maquina_id'  => $linea->maquina_id,
        'proyecto_id' => $proyecto->id,
    ]);

    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacion->id,
        'horas'                 => 10,
        'horas_extra'           => 0,
    ]);

    $resultado = app(FinalizarRentaService::class)->finalizar($proyecto);

    // Extra: 2 h × L 950 = 1,900 + ISV 15% = 2,185.
    expect($resultado['extra'])->toBe('2185.00')
        ->and($resultado['proyecto']->estado)->toBe(EstadoProyecto::Finalizada);

    $cuenta = CuentaPorCobrar::where('proyecto_id', $proyecto->id)->first();
    expect($cuenta->monto_original)->toBe('10925.00') // 8,740 + 2,185
        ->and($cuenta->saldo)->toBe('10925.00');
});

test('finalizar sin superar lo pactado cobra exactamente lo cotizado', function (): void {
    $proyecto = crearRentaConLinea();
    $linea = $proyecto->lineasRenta->first();

    app(AprobarRentaService::class)->aprobar($proyecto);
    $proyecto->refresh();

    // Trabajó MENOS de lo pactado (6 de 8): lo cotizado es el mínimo.
    $asignacion = AsignacionMaquina::factory()->create([
        'maquina_id'  => $linea->maquina_id,
        'proyecto_id' => $proyecto->id,
    ]);

    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacion->id,
        'horas'                 => 6,
        'horas_extra'           => 0,
    ]);

    $resultado = app(FinalizarRentaService::class)->finalizar($proyecto);

    // El extra es dinero: siempre dos decimales, aunque sea cero.
    expect($resultado['extra'])->toBe('0.00');

    $cuenta = CuentaPorCobrar::where('proyecto_id', $proyecto->id)->first();
    expect($cuenta->monto_original)->toBe('8740.00');
});

test('las líneas de renta solo se agregan en borrador (salvo extensiones)', function (): void {
    $proyecto = crearRentaConLinea();
    $maquina = $proyecto->lineasRenta->first()->maquina;

    app(AprobarRentaService::class)->aprobar($proyecto);
    $proyecto->refresh();

    expect(fn () => app(AgregarLineaRentaService::class)->agregar(
        $proyecto,
        $maquina->id,
        UnidadRenta::Hora,
        '4',
        now()->addDays(3)->toDateString(),
    ))->toThrow(RentaInvalidaException::class);
});

test('un proyecto presupuestado no acepta líneas de renta', function (): void {
    $presupuestado = Proyecto::factory()->create();
    $maquina = Maquina::factory()->create();

    expect(fn () => app(AgregarLineaRentaService::class)->agregar(
        $presupuestado,
        $maquina->id,
        UnidadRenta::Hora,
        '8',
        now()->addDays(2)->toDateString(),
    ))->toThrow(RentaInvalidaException::class);
});

test('el proyecto conoce su cuenta pendiente y la suelta al saldarse', function (): void {
    $proyecto = crearRentaConLinea();

    // En borrador todavía no hay deuda.
    expect($proyecto->cuentaPorCobrarPendiente())->toBeNull();

    app(AprobarRentaService::class)->aprobar($proyecto);
    $proyecto->refresh();

    $cuenta = $proyecto->cuentaPorCobrarPendiente();
    expect($cuenta)->not->toBeNull()
        ->and($cuenta->saldo)->toBe('8740.00');

    // Anticipo parcial: sigue pendiente.
    app(CobrarService::class)->cobrar($cuenta, '4000');
    expect($proyecto->cuentaPorCobrarPendiente()?->saldo)->toBe('4740.00');

    // Cobro final: el proyecto ya no debe nada.
    app(CobrarService::class)->cobrar($cuenta->refresh(), '4740');
    expect($proyecto->cuentaPorCobrarPendiente())->toBeNull();
});
