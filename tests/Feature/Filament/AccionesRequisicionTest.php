<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;
use App\Enums\ResolucionLinea;
use App\Filament\Resources\Requisiciones\Pages\ListRequisiciones;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Requisiciones\TransicionarRequisicionService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Tests de las acciones de transición del RequisicionResource (Filament 2b).
|--------------------------------------------------------------------------
| Verifican que las acciones de la tabla llaman al motor: autorizar avanza
| el estado y fija cantidades; revisar el pedido resuelve renglón por
| renglón y mueve stock real con WAC.
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(Utils::getSuperAdminName());

    Gate::before(fn ($user): ?bool => $user instanceof User && $user->hasRole(Utils::getSuperAdminName())
        ? true
        : null);

    $this->actingAs($this->admin);

    $this->inventario = new RegistrarMovimientoService;
    // Por el container: el constructor también inyecta el notificador.
    $this->transiciones = app(TransicionarRequisicionService::class);
    $this->bodega = Bodega::factory()->create();
    $this->proyecto = Proyecto::factory()->create();
});

test('la acción Autorizar avanza el estado y fija la cantidad autorizada', function (): void {
    $material = Material::factory()->create();
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    $linea = RequisicionLinea::factory()->create([
        'requisicion_id'      => $requisicion->id,
        'material_id'         => $material->id,
        'cantidad_solicitada' => 100,
    ]);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('autorizar', $requisicion, [
            'lineas' => [[
                'linea_id'            => $linea->id,
                'material'            => 'X',
                'cantidad_solicitada' => '100',
                'cantidad'            => '80',
            ]],
        ])
        ->assertHasNoTableActionErrors();

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Autorizada)
        ->and($linea->fresh()->cantidad_autorizada)->toBe('80.0000');
});

test('Revisar pedido marcado "sale de bodega" mueve stock real a la obra', function (): void {
    $material = Material::factory()->create();
    $this->inventario->entradaCompra($material->id, Ubicacion::bodega($this->bodega->id), '200', '10');

    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    $linea = RequisicionLinea::factory()->create([
        'requisicion_id'      => $requisicion->id,
        'material_id'         => $material->id,
        'cantidad_solicitada' => 100,
    ]);

    // Autorizar vía Service (precondición del despacho).
    $this->transiciones->autorizar($requisicion);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('revisar_pedido', $requisicion, [
            'bodega_id' => $this->bodega->id,
            'lineas'    => [[
                'linea_id'    => $linea->id,
                'material_id' => $material->id,
                'pendiente'   => '100',
                'resolucion'  => ResolucionLinea::Bodega->value,
                'cantidad'    => '100',
            ]],
            'nota_general' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Despachada);

    $stockBodega = Existencia::query()
        ->where('material_id', $material->id)
        ->where('bodega_id', $this->bodega->id)
        ->value('cantidad');

    $stockObra = Existencia::query()
        ->where('material_id', $material->id)
        ->where('proyecto_id', $this->proyecto->id)
        ->value('cantidad');

    expect((string) $stockBodega)->toBe('100.0000')
        ->and((string) $stockObra)->toBe('100.0000');
});

test('con la fecha necesaria vencida se oculta Autorizar y aparece Reprogramar', function (): void {
    $material = Material::factory()->create();
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create([
        // CHECK fechas_coherentes: necesaria >= solicitud.
        'fecha_solicitud' => today()->subDays(10),
        'fecha_necesaria' => today()->subDays(3),
    ]);
    RequisicionLinea::factory()->create([
        'requisicion_id'      => $requisicion->id,
        'material_id'         => $material->id,
        'cantidad_solicitada' => 10,
    ]);

    Livewire::test(ListRequisiciones::class)
        ->assertTableActionHidden('autorizar', $requisicion)
        ->assertTableActionVisible('reprogramar', $requisicion)
        // Rechazar sigue disponible: es el destino natural de muchas vencidas.
        ->assertTableActionVisible('rechazar', $requisicion);
});

test('sin vencer, Autorizar es visible y Reprogramar no', function (): void {
    $material = Material::factory()->create();
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create([
        'fecha_necesaria' => today()->addDays(3),
    ]);
    RequisicionLinea::factory()->create([
        'requisicion_id'      => $requisicion->id,
        'material_id'         => $material->id,
        'cantidad_solicitada' => 10,
    ]);

    Livewire::test(ListRequisiciones::class)
        ->assertTableActionVisible('autorizar', $requisicion)
        ->assertTableActionHidden('reprogramar', $requisicion);
});

test('la acción Reprogramar actualiza la fecha y deja el motivo en bitácora', function (): void {
    $material = Material::factory()->create();
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create([
        // CHECK fechas_coherentes: necesaria >= solicitud.
        'fecha_solicitud' => today()->subDays(10),
        'fecha_necesaria' => today()->subDays(3),
    ]);
    RequisicionLinea::factory()->create([
        'requisicion_id'      => $requisicion->id,
        'material_id'         => $material->id,
        'cantidad_solicitada' => 10,
    ]);

    $nueva = today()->addDays(2);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('reprogramar', $requisicion, [
            'fecha_necesaria' => $nueva->toDateString(),
            'motivo'          => 'Se atrasó el vaciado de losa',
        ])
        ->assertHasNoTableActionErrors();

    $requisicion = $requisicion->fresh();
    expect($requisicion->fecha_necesaria->toDateString())->toBe($nueva->toDateString())
        ->and($requisicion->estado)->toBe(EstadoRequisicion::Solicitada);

    $transicion = $requisicion->transiciones()->latest('id')->first();
    expect($transicion->user_id)->toBe($this->admin->id)
        ->and($transicion->nota)->toContain('Se atrasó el vaciado de losa');
});

test('marcar un renglón "se compra" manda la requisición a Requisición de compra', function (): void {
    $material = Material::factory()->create(); // sin stock
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    $linea = RequisicionLinea::factory()->create([
        'requisicion_id'      => $requisicion->id,
        'material_id'         => $material->id,
        'cantidad_solicitada' => 10,
    ]);
    $this->transiciones->autorizar($requisicion);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('revisar_pedido', $requisicion, [
            'bodega_id' => $this->bodega->id,
            'lineas'    => [[
                'linea_id'    => $linea->id,
                'material_id' => $material->id,
                'pendiente'   => '10',
                'resolucion'  => ResolucionLinea::Comprar->value,
            ]],
            'nota_general' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::RequisicionCompra)
        ->and($linea->fresh()->resolucion)->toBe(ResolucionLinea::Comprar);
});

/*
|--------------------------------------------------------------------------
| Revisión renglón por renglón (Mauricio 2026-09-10).
|--------------------------------------------------------------------------
| "Si hay en bodega salen de ahí; si no hay y se compraron, le llegarán; si
| no se pudo comprar, se marca que ese no le llegará." Lo que se prueba acá
| es lo que ANTES no se podía: que las tres respuestas convivan en un mismo
| pedido sin que una arrastre a las otras.
*/

test('un mismo pedido despacha lo que hay, pide comprar lo que falta y cierra lo que no se consiguió', function (): void {
    $hay = Material::factory()->create();
    $seCompra = Material::factory()->create();
    $noHay = Material::factory()->create();

    $this->inventario->entradaCompra($hay->id, Ubicacion::bodega($this->bodega->id), '500', '10');

    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();

    $lineaHay = RequisicionLinea::factory()->create([
        'requisicion_id' => $requisicion->id, 'material_id' => $hay->id, 'cantidad_solicitada' => 100,
    ]);
    $lineaCompra = RequisicionLinea::factory()->create([
        'requisicion_id' => $requisicion->id, 'material_id' => $seCompra->id, 'cantidad_solicitada' => 20,
    ]);
    $lineaNoHay = RequisicionLinea::factory()->create([
        'requisicion_id' => $requisicion->id, 'material_id' => $noHay->id, 'cantidad_solicitada' => 5,
    ]);

    $this->transiciones->autorizar($requisicion);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('revisar_pedido', $requisicion, [
            'bodega_id' => $this->bodega->id,
            'lineas'    => [
                ['linea_id' => $lineaHay->id, 'material_id' => $hay->id, 'pendiente' => '100', 'resolucion' => ResolucionLinea::Bodega->value, 'cantidad' => '100'],
                ['linea_id' => $lineaCompra->id, 'material_id' => $seCompra->id, 'pendiente' => '20', 'resolucion' => ResolucionLinea::Comprar->value],
                ['linea_id' => $lineaNoHay->id, 'material_id' => $noHay->id, 'pendiente' => '5', 'resolucion' => ResolucionLinea::NoDisponible->value, 'nota' => 'No hay en ninguna ferretería de la zona'],
            ],
            'nota_general' => null,
        ])
        ->assertHasNoTableActionErrors();

    // Falta llegar lo que se compra: la cabecera queda en compra...
    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::RequisicionCompra);

    // ...pero lo que SÍ había ya salió (antes esto se quedaba en bodega).
    expect((string) $lineaHay->fresh()->cantidad_despachada)->toBe('100.0000');

    $stockObra = Existencia::query()
        ->where('material_id', $hay->id)
        ->where('proyecto_id', $this->proyecto->id)
        ->value('cantidad');

    expect((string) $stockObra)->toBe('100.0000');

    // Y lo que no se consiguió queda cerrado, con su motivo y sin
    // perseguir a nadie: autorizada baja a lo despachado (cero).
    $cerrada = $lineaNoHay->fresh();

    expect($cerrada->resolucion)->toBe(ResolucionLinea::NoDisponible)
        ->and($cerrada->noLlega())->toBeTrue()
        ->and($cerrada->resolucion_nota)->toContain('ferretería')
        ->and((string) $cerrada->cantidad_autorizada)->toBe('0.0000');
});

test('si no se consiguió NADA la requisición queda rechazada', function (): void {
    $material = Material::factory()->create();
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    $linea = RequisicionLinea::factory()->create([
        'requisicion_id' => $requisicion->id, 'material_id' => $material->id, 'cantidad_solicitada' => 8,
    ]);
    $this->transiciones->autorizar($requisicion);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('revisar_pedido', $requisicion, [
            'bodega_id' => $this->bodega->id,
            'lineas'    => [[
                'linea_id'    => $linea->id,
                'material_id' => $material->id,
                'pendiente'   => '8',
                'resolucion'  => ResolucionLinea::NoDisponible->value,
                'nota'        => 'Descontinuado por el proveedor',
            ]],
            'nota_general' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Rechazada);
});

test('lo que sale de bodega a medias deja el resto por comprar', function (): void {
    $material = Material::factory()->create();
    $this->inventario->entradaCompra($material->id, Ubicacion::bodega($this->bodega->id), '30', '10');

    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    $linea = RequisicionLinea::factory()->create([
        'requisicion_id' => $requisicion->id, 'material_id' => $material->id, 'cantidad_solicitada' => 100,
    ]);
    $this->transiciones->autorizar($requisicion);

    Livewire::test(ListRequisiciones::class)
        ->callTableAction('revisar_pedido', $requisicion, [
            'bodega_id' => $this->bodega->id,
            'lineas'    => [[
                'linea_id'    => $linea->id,
                'material_id' => $material->id,
                'pendiente'   => '100',
                'resolucion'  => ResolucionLinea::Bodega->value,
                'cantidad'    => '30',
            ]],
            'nota_general' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::RequisicionCompra)
        ->and((string) $linea->fresh()->cantidad_despachada)->toBe('30.0000');
});
