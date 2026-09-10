<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Enums\EstadoRequisicion;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Exceptions\Compras\CompraNoVerificableException;
use App\Exceptions\Compras\LlegadaNoReprogramableException;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\User;
use App\Services\Compras\AvisarLlegadasComprasService;
use App\Services\Compras\MarcarPorRecibirService;
use App\Services\Compras\ReprogramarLlegadaCompraService;
use App\Services\Compras\VerificarRecepcionService;
use App\Services\Requisiciones\SeguimientoLlegadaRequisicionService;
use App\Support\Roles;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| La obra se entera de CUÁNDO llega su material (decisión 2026-08-07).
|--------------------------------------------------------------------------
| Caso real: la obra pide para el 10, compras compra el 8 y el proveedor
| entrega el 9. El encargado la esperaba hasta el 10 y nadie le avisó del
| adelanto. Con un atraso es peor: la cuadrilla se queda parada.
|
| La ventana de incertidumbre es mientras la requisición está en
| "Requisición de compra": ahí vive el seguimiento.
*/

beforeEach(function (): void {
    foreach ([Roles::RECEPCION, Roles::GERENCIA, Roles::ENCARGADO_OBRA] as $rol) {
        Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
    }

    $this->seguimiento = app(SeguimientoLlegadaRequisicionService::class);
    $this->proyecto = Proyecto::factory()->create();
    $this->material = Material::factory()->create();

    $this->encargado = User::factory()->create(['is_active' => true]);
    $this->encargado->assignRole(Roles::ENCARGADO_OBRA);
    $this->proyecto->encargados()->attach($this->encargado->id);
});

/**
 * Requisición esperando compra, necesaria el $necesaria.
 */
function requisicionQueEspera(Proyecto $proyecto, Material $material, string $necesaria): Requisicion
{
    $requisicion = Requisicion::factory()
        ->paraProyecto($proyecto)
        ->enEstado(EstadoRequisicion::RequisicionCompra)
        ->create(['fecha_necesaria' => $necesaria]);

    RequisicionLinea::factory()->paraMaterial($material)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '10.0000',
        'cantidad_autorizada' => '10.0000',
    ]);

    return $requisicion;
}

/**
 * Compra directa a obra "por recibir" que promete llegar el $llegada.
 */
function compraEnCamino(Proyecto $proyecto, Material $material, Requisicion $requisicion, ?string $llegada): Compra
{
    $compra = Compra::factory()
        ->directaAObra($proyecto)
        ->paraRequisicion($requisicion)
        ->enEstado(EstadoCompra::PorRecibir)
        ->create(['fecha_estimada_llegada' => $llegada]);

    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => $material->id,
        'cantidad'       => 10,
        'costo_unitario' => 10,
    ]);

    return $compra;
}

/*
| Las fechas van SIEMPRE relativas a hoy: con literales el test caducaba
| solo. La factory pone fecha_solicitud = HOY y el CHECK
| requisiciones_fechas_coherentes exige fecha_necesaria >= solicitud, así
| que una fecha escrita a mano reventaba a la semana siguiente.
*/

/** El día para el que la obra pide el material. */
function fechaPedida(): string
{
    return today()->addDays(5)->toDateString();
}

/** El proveedor entrega un día ANTES de lo necesario. */
function entregaAdelantada(): string
{
    return today()->addDays(4)->toDateString();
}

/** El proveedor entrega tres días DESPUÉS: llega tarde. */
function entregaAtrasada(): string
{
    return today()->addDays(8)->toDateString();
}

test('GOLDEN: al programar la llegada, la obra recibe la fecha y la comparación con lo que pidió', function (): void {
    // Pide para el 10, el proveedor entrega el 9: llega un día ANTES.
    $requisicion = requisicionQueEspera($this->proyecto, $this->material, fechaPedida());
    $compra = compraEnCamino($this->proyecto, $this->material, $requisicion, entregaAdelantada());

    $this->seguimiento->programar($compra);

    $requisicion->refresh();

    expect($requisicion->fecha_estimada_llegada?->toDateString())->toBe(entregaAdelantada())
        ->and($requisicion->llegaTarde())->toBeFalse()
        ->and($requisicion->esperandoLlegada())->toBeTrue();

    // Campanita al encargado con la comparación contra la fecha necesaria.
    $campanita = json_encode($this->encargado->notifications()->first()?->data);

    expect($this->encargado->notifications()->count())->toBe(1)
        ->and($campanita)->toContain($compra->codigo)
        ->and($campanita)->toContain('antes');

    // Y queda el rastro en la bitácora de la requisición.
    expect($requisicion->transiciones()->where('nota', 'like', '%Llegada programada%')->exists())->toBeTrue();
});

test('cuando la entrega cae DESPUÉS de la fecha necesaria, el aviso lo dice en la cara', function (): void {
    $requisicion = requisicionQueEspera($this->proyecto, $this->material, fechaPedida());
    $compra = compraEnCamino($this->proyecto, $this->material, $requisicion, entregaAtrasada());

    $this->seguimiento->programar($compra);

    expect($requisicion->refresh()->llegaTarde())->toBeTrue()
        ->and(json_encode($this->encargado->notifications()->first()?->data))->toContain('TARDE');
});

test('el pedido directo a obra desde una requisición NO se registra sin fecha de llegada', function (): void {
    $requisicion = requisicionQueEspera($this->proyecto, $this->material, fechaPedida());

    $compra = Compra::factory()
        ->directaAObra($this->proyecto)
        ->paraRequisicion($requisicion)
        ->create(['fecha_estimada_llegada' => null]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $this->material->id,
        'cantidad'  => 10, 'costo_unitario' => 10,
    ]);

    expect(fn () => app(MarcarPorRecibirService::class)->registrar($compra))
        ->toThrow(CompraNoConfirmableException::class, 'fecha estimada de llegada');
});

test('reprogramar la llegada deja el anterior → nuevo en bitácora y avisa del ATRASO', function (): void {
    $requisicion = requisicionQueEspera($this->proyecto, $this->material, fechaPedida());
    $compra = compraEnCamino($this->proyecto, $this->material, $requisicion, entregaAdelantada());

    $this->seguimiento->programar($compra);
    $this->encargado->notifications()->delete();

    app(ReprogramarLlegadaCompraService::class)->reprogramar(
        $compra,
        Carbon::parse(entregaAtrasada()),
        'El proveedor no tuvo el material en bodega.',
    );

    $requisicion->refresh();

    $rastro = today()->addDays(4)->format('d/m/Y').' → '.today()->addDays(8)->format('d/m/Y');

    expect($requisicion->fecha_estimada_llegada?->toDateString())->toBe(entregaAtrasada())
        ->and($requisicion->llegaTarde())->toBeTrue()
        ->and($compra->refresh()->aviso_llegada_at)->toBeNull()
        ->and($requisicion->transiciones()->where('nota', 'like', "%{$rastro}%")->exists())->toBeTrue();

    expect(json_encode($this->encargado->notifications()->first()?->data))->toContain('ATRASO');
});

test('reprogramar exige motivo y no admite fechas en el pasado', function (): void {
    $requisicion = requisicionQueEspera($this->proyecto, $this->material, fechaPedida());
    $compra = compraEnCamino($this->proyecto, $this->material, $requisicion, entregaAdelantada());

    expect(fn () => app(ReprogramarLlegadaCompraService::class)->reprogramar($compra, today()->addDay(), '   '))
        ->toThrow(LlegadaNoReprogramableException::class, 'motivo');

    expect(fn () => app(ReprogramarLlegadaCompraService::class)->reprogramar($compra, today()->subDay(), 'x'))
        ->toThrow(LlegadaNoReprogramableException::class, 'hoy o futura');
});

test('el día de la entrega la obra recibe "hoy llega", y una sola vez', function (): void {
    $requisicion = requisicionQueEspera($this->proyecto, $this->material, today()->toDateString());
    $compra = compraEnCamino($this->proyecto, $this->material, $requisicion, today()->toDateString());
    $this->seguimiento->programar($compra);
    $this->encargado->notifications()->delete();

    $servicio = app(AvisarLlegadasComprasService::class);

    $servicio->avisar();

    expect($this->encargado->notifications()->count())->toBe(1)
        ->and(json_encode($this->encargado->notifications()->first()?->data))->toContain('Hoy llega');

    // Segunda pasada el MISMO día: no repite.
    $servicio->avisar();

    expect($this->encargado->notifications()->count())->toBe(1);
});

test('si la fecha prometida pasó y el material no llegó, el reclamo se repite cada día', function (): void {
    $requisicion = requisicionQueEspera($this->proyecto, $this->material, today()->toDateString());
    $compra = compraEnCamino($this->proyecto, $this->material, $requisicion, today()->subDays(2)->toDateString());
    $this->seguimiento->programar($compra);
    $this->encargado->notifications()->delete();

    // Ya se avisó ayer y el pedido sigue sin aparecer.
    $requisicion->forceFill(['aviso_llegada_obra_at' => now()->subDay()])->save();

    app(AvisarLlegadasComprasService::class)->avisar();

    expect(json_encode($this->encargado->notifications()->first()?->data))
        ->toContain('no ha entregado');
});

test('no se puede verificar la recepción antes del día que el proveedor prometió', function (): void {
    $requisicion = requisicionQueEspera($this->proyecto, $this->material, today()->addDays(15)->toDateString());
    $compra = compraEnCamino(
        $this->proyecto,
        $this->material,
        $requisicion,
        today()->addDays(2)->toDateString(),
    );
    $linea = $compra->lineas()->firstOrFail();

    // Verificar mete stock a la obra y crea la CxP: hacerlo antes de que
    // el proveedor entregue sería inventario fantasma y deuda por algo
    // que no llegó.
    expect(fn () => app(VerificarRecepcionService::class)
        ->verificar($compra, [$linea->id => '10'], $this->encargado))
        ->toThrow(CompraNoVerificableException::class, 'entrega el');
});
