<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Enums\ModalidadTrabajo;
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
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\RegistrarParteService;
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

    $maquina = Maquina::factory()->create(['tarifa_hora' => 950, 'horas_dia_renta' => 8]);

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

test('cada presentación de renta cotiza con SU precio, no derivado de la hora', function (): void {
    $proyecto = Proyecto::factory()->renta()->create();

    // El día NO es 8 × la hora: el mercado de renta da descuento por volumen
    // (la hora ronda el 15% del día). Derivarlo sobrecotizaba ~20%, así que
    // cada presentación lleva su propio precio en el rate card.
    $maquina = Maquina::factory()->create([
        'tarifa_hora'     => 1000,
        'tarifa_dia'      => 6700,
        'tarifa_semana'   => 33000,
        'horas_dia_renta' => 8,
    ]);

    $porDia = app(AgregarLineaRentaService::class)->agregar(
        $proyecto,
        $maquina->id,
        UnidadRenta::Dia,
        '2',
        now()->addDays(2)->toDateString(),
    );

    expect($porDia->tarifa_snapshot)->toBe('6700.00')
        ->and($porDia->subtotal_cache)->toBe('13400.00');

    $porSemana = app(AgregarLineaRentaService::class)->agregar(
        $proyecto,
        $maquina->id,
        UnidadRenta::Semana,
        '1',
        now()->addDays(8)->toDateString(),
    );

    expect($porSemana->tarifa_snapshot)->toBe('33000.00');
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

test('el excedente sobre la jornada se cobra UNA vez, no dos', function (): void {
    // Regresión del 2026-08-07. RegistrarParteService deriva las horas
    // extra restando la jornada, así que un día de 10 h con jornada de 8
    // se guarda como horas=10 y horas_extra=2 — el excedente vive DENTRO
    // del total. Al comparar pactado contra real se sumaban las dos y el
    // sistema leía 12 h: se le facturaban 4 h de extra al cliente en vez
    // de 2. Este test registra el parte por la puerta real para que la
    // derivación ocurra de verdad.
    $proyecto = crearRentaConLinea();
    $linea = $proyecto->lineasRenta->first();

    app(AprobarRentaService::class)->aprobar($proyecto);
    $proyecto->refresh();

    $asignacion = app(AsignarMaquinaService::class)->asignar(
        $linea->maquina,
        $proyecto->id,
        tarifaPactada: '950',
    );

    $parte = app(RegistrarParteService::class)->registrarManual(
        asignacion: $asignacion,
        horas: '10',
    );

    // El parte guarda el total del día y nada más: desde 2026-09-04 no
    // calcula horas extra, porque "la jornada de la máquina" no era un dato
    // real (hay días de 4 horas y días de 12). El excedente sobre lo
    // CONTRATADO lo mide la renta al finalizar, comparando contra la unidad
    // pactada — que es lo que verifica la aserción de abajo.
    expect($parte->horas)->toBe('10.00')
        ->and($parte->horas_extra)->toBe('0.00');

    $resultado = app(FinalizarRentaService::class)->finalizar($proyecto);

    // Pactadas 8, reales 10 → 2 h × L 950 = 1,900 + ISV 15% = 2,185.
    expect($resultado['extra'])->toBe('2185.00');
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

test('DOS DIMENSIONES: el día que se cobra por viajes no se cobra otra vez por horas', function (): void {
    // Volqueta con renta mixta: 8 h pactadas Y 2 viajes pactados. El
    // parte anota las horas del día en TODAS las modalidades, así que
    // sumarlas en la dimensión "horas" facturaba el mismo día dos veces
    // (2026-08-16).
    $proyecto = Proyecto::factory()->renta()->for(Cliente::factory()->create())->create();

    $maquina = Maquina::factory()->create([
        'tarifa_hora'     => 950,
        'tarifa_viaje'    => 1200,
        'horas_dia_renta' => 8,
    ]);

    $agregar = app(AgregarLineaRentaService::class);
    $llegada = now()->addDays(2)->toDateString();

    $agregar->agregar($proyecto, $maquina->id, UnidadRenta::Hora, '8', $llegada, '07:00');
    $agregar->agregar($proyecto, $maquina->id, UnidadRenta::Viaje, '2', $llegada, '07:00');

    app(AprobarRentaService::class)->aprobar($proyecto->refresh());
    $proyecto->refresh();

    // Un solo día real: 9 horas y 10 viajes, registrado POR VIAJES.
    $asignacion = AsignacionMaquina::factory()->create([
        'maquina_id'  => $maquina->id,
        'proyecto_id' => $proyecto->id,
    ]);

    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacion->id,
        'modalidad'             => ModalidadTrabajo::Viajes,
        'horas'                 => 9,
        'horas_extra'           => 0,
        'viajes'                => 10,
    ]);

    $resultado = app(FinalizarRentaService::class)->finalizar($proyecto);

    // SOLO el excedente de viajes: (10 − 2) × 1,200 = 9,600 + 15% = 11,040.
    // La hora extra sobre la jornada NO se cobra aparte: ese día ya se
    // facturó por viajes.
    expect($resultado['extra'])->toBe('11040.00');
});

test('DOS DIMENSIONES: con renta solo por horas, el parte por viajes sí aporta sus horas', function (): void {
    // La volqueta se rentó POR HORAS aunque su modalidad de catálogo sea
    // viajes: nada se cobra por viajes, así que esas horas cuentan.
    $proyecto = crearRentaConLinea();
    $linea = $proyecto->lineasRenta->first();

    app(AprobarRentaService::class)->aprobar($proyecto);
    $proyecto->refresh();

    $asignacion = AsignacionMaquina::factory()->create([
        'maquina_id'  => $linea->maquina_id,
        'proyecto_id' => $proyecto->id,
    ]);

    ParteTrabajo::factory()->create([
        'asignacion_maquina_id' => $asignacion->id,
        'modalidad'             => ModalidadTrabajo::Viajes,
        'horas'                 => 10,
        'horas_extra'           => 0,
        'viajes'                => 4,
    ]);

    // 2 h sobre las 8 pactadas × L 950 = 1,900 + ISV = 2,185.
    expect(app(FinalizarRentaService::class)->finalizar($proyecto)['extra'])->toBe('2185.00');
});
