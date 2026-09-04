<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Enums\RubroCostoArranque;
use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Models\Cliente;
use App\Models\CostoArranqueProyecto;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\User;
use App\Models\Zona;
use App\Services\Proyectos\CalcularPrecioProyectoService;
use App\Services\Proyectos\RegistrarCostoArranqueService;
use App\Services\Reportes\CostoProyectoService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Obra heredada — carga inicial (2026-09-03)
|--------------------------------------------------------------------------
| MAYAP arranca con obras a medio construir. Dos cosas no se pueden pedir:
| reconstruir las fichas APU con precios de hace meses (por eso el monto
| contratado a mano), y recordar todo el gasto previo de una sentada (por
| eso las partidas con fecha y nota). Sin esto la obra entra con costo real
| 0 — margen 100% — y el primer mes de gastos parece un descontrol.
*/

beforeEach(function (): void {
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
});

/** Obra viva con presupuesto conocido. */
function obraEnEjecucion(string $presupuesto = '100000.00'): Proyecto
{
    return Proyecto::factory()
        ->enZona(test()->zona)
        ->paraCliente(test()->cliente)
        ->enEjecucion()
        ->create(['subtotal_cache' => $presupuesto]);
}

// ── El presupuesto de una obra heredada ────────────────────────────────

test('el presupuesto de una obra heredada sale del monto contratado, no de los renglones', function (): void {
    $obra = obraEnEjecucion('0.00');
    $obra->forceFill([
        'es_obra_heredada' => true,
        'monto_contratado' => '850000.00',
        // El CHECK proyectos_isv_consistente exige que al apagar el ISV el
        // porcentaje quede en 0; si no, el update se rechaza.
        'aplica_isv'     => false,
        'isv_porcentaje' => 0,
    ])->save();

    $recalculado = app(CalcularPrecioProyectoService::class)->recalcular($obra);

    // Sin un solo renglón cargado, el presupuesto ya es el del contrato.
    expect($recalculado->renglones()->count())->toBe(0)
        ->and($recalculado->subtotal_cache)->toBe('850000.00')
        ->and($recalculado->total_cache)->toBe('850000.00');
});

test('los renglones de una obra heredada NO mueven el monto contratado', function (): void {
    $obra = obraEnEjecucion('0.00');
    $obra->forceFill([
        'es_obra_heredada' => true,
        'monto_contratado' => '850000.00',
        // El CHECK proyectos_isv_consistente exige que al apagar el ISV el
        // porcentaje quede en 0; si no, el update se rechaza.
        'aplica_isv'     => false,
        'isv_porcentaje' => 0,
    ])->save();

    // Cargados después, solo para control por capítulo.
    $obra->renglones()->create([
        'ficha_id'                 => Ficha::factory()->enZona($this->zona)->create()->id,
        'orden'                    => 1,
        'cantidad'                 => '10.0000',
        'precio_unitario_snapshot' => '100.00',
        'subtotal_cache'           => '1000.00',
    ]);

    expect(app(CalcularPrecioProyectoService::class)->recalcular($obra)->subtotal_cache)
        ->toBe('850000.00');
});

test('una obra normal sigue calculando desde sus renglones', function (): void {
    $obra = obraEnEjecucion('0.00');
    $obra->forceFill([
        'monto_contratado' => '850000.00',
        'aplica_isv'       => false,
        'isv_porcentaje'   => 0,
    ])->save();

    // monto_contratado cargado pero SIN marcar heredada: se ignora.
    expect($obra->usaMontoContratado())->toBeFalse()
        ->and(app(CalcularPrecioProyectoService::class)->recalcular($obra)->subtotal_cache)
        ->toBe('0.00');
});

// ── Las partidas del gasto previo ──────────────────────────────────────

test('un Proyecto recién instanciado no arrastra costo', function (): void {
    $proyecto = new Proyecto;

    expect($proyecto->costoArranqueTotal())->toBe('0.00')
        ->and($proyecto->tieneCostoArranque())->toBeFalse();
});

test('las partidas suman al costo real por su propio rubro', function (): void {
    $obra = obraEnEjecucion();
    $service = app(RegistrarCostoArranqueService::class);

    $service->agregar($obra, RubroCostoArranque::Materiales, 20000, 'Hierro y cemento, mayo');
    $service->agregar($obra, RubroCostoArranque::Materiales, 10000, 'Bloque, junio');
    $service->agregar($obra, RubroCostoArranque::ManoObra, 15000, 'Planilla de albañiles');
    $service->agregar($obra, RubroCostoArranque::Maquinaria, 5000, 'Retro, 2 días');

    $costo = app(CostoProyectoService::class)->calcular($obra->refresh());

    expect($costo->costoMateriales)->toBe('30000.00')
        ->and($costo->costoManoObra)->toBe('15000.00')
        ->and($costo->costoMaquinaria)->toBe('5000.00')
        ->and($costo->costoTotal)->toBe('50000.00')
        ->and($costo->costoArranque)->toBe('50000.00')
        ->and($costo->margen)->toBe('50000.00')
        ->and($costo->porcentajeConsumido)->toBe('50.00');
});

test('borrar una partida baja el arrastre', function (): void {
    $obra = obraEnEjecucion();
    $service = app(RegistrarCostoArranqueService::class);

    $service->agregar($obra, RubroCostoArranque::Materiales, 20000, 'Hierro, mayo');
    $sobra = $service->agregar($obra, RubroCostoArranque::Materiales, 8000, 'Cargada por error');

    expect($obra->refresh()->costoArranqueTotal())->toBe('28000.00');

    $service->eliminar($sobra);

    expect($obra->refresh()->costoArranqueTotal())->toBe('20000.00')
        ->and($obra->costosArranque()->count())->toBe(1);
});

test('sin partidas la obra sigue calculando como antes', function (): void {
    $costo = app(CostoProyectoService::class)->calcular(obraEnEjecucion());

    expect($costo->costoTotal)->toBe('0.00')
        ->and($costo->costoArranque)->toBe('0.00')
        ->and($costo->margen)->toBe('100000.00');
});

test('el cache por rubro se puede reconstruir desde las partidas', function (): void {
    $obra = obraEnEjecucion();

    CostoArranqueProyecto::factory()
        ->for($obra, 'proyecto')
        ->delRubro(RubroCostoArranque::ManoObra)
        ->porMonto('7500.00')
        ->create();

    // Insertada por fuera del service: el cache todavía está en cero.
    expect($obra->refresh()->costoArranqueTotal())->toBe('0.00');

    app(RegistrarCostoArranqueService::class)->recalcularCache($obra);

    expect($obra->refresh()->costo_arranque_mano_obra)->toBe('7500.00');
});

// ── Guardas ────────────────────────────────────────────────────────────

test('un monto en cero o negativo se rechaza', function (): void {
    app(RegistrarCostoArranqueService::class)
        ->agregar(obraEnEjecucion(), RubroCostoArranque::Materiales, 0, 'Nada');
})->throws(DatosEjecucionInvalidosException::class);

test('una partida sin decir de qué era se rechaza', function (): void {
    app(RegistrarCostoArranqueService::class)
        ->agregar(obraEnEjecucion(), RubroCostoArranque::Materiales, 1000, '   ');
})->throws(DatosEjecucionInvalidosException::class);

test('un proyecto en borrador no admite gasto anterior', function (): void {
    $borrador = Proyecto::factory()->enZona($this->zona)->paraCliente($this->cliente)->create();

    expect($borrador->estado)->toBe(EstadoProyecto::Borrador);

    app(RegistrarCostoArranqueService::class)
        ->agregar($borrador, RubroCostoArranque::Materiales, 1000, 'Hierro');
})->throws(DatosEjecucionInvalidosException::class);

test('la partida guarda de qué era, de cuándo y quién la cargó', function (): void {
    $obra = obraEnEjecucion();
    $usuario = User::factory()->create();

    $partida = app(RegistrarCostoArranqueService::class)->agregar(
        $obra,
        RubroCostoArranque::Materiales,
        1000,
        '  Hierro 3/8, factura 4412  ',
        today()->subMonths(2),
        $usuario->id,
    );

    expect($partida->descripcion)->toBe('Hierro 3/8, factura 4412')
        ->and($partida->fecha->toDateString())->toBe(today()->subMonths(2)->toDateString())
        ->and($partida->registradoPor?->id)->toBe($usuario->id);
});

test('el CHECK de la DB rechaza un monto no positivo', function (): void {
    $obra = obraEnEjecucion();

    DB::table('costos_arranque_proyecto')->insert([
        'proyecto_id' => $obra->id,
        'rubro'       => RubroCostoArranque::Materiales->value,
        'monto'       => '0.00',
        'fecha'       => today()->toDateString(),
        'descripcion' => 'INVALIDA',
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
})->throws(QueryException::class);
