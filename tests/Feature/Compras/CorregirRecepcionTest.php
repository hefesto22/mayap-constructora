<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Enums\EstadoRequisicion;
use App\Exceptions\Compras\CompraNoCorregibleException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\User;
use App\Services\Compras\CorregirRecepcionService;
use App\Services\Compras\MarcarPorRecibirService;
use App\Services\Compras\VerificarRecepcionService;
use App\Support\Permisos;
use App\Support\Roles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Corrección de conteos — "dije 40 y eran 60".
|--------------------------------------------------------------------------
| Por recibir: solo re-captura (sin stock). Confirmada: la diferencia se
| mueve de inventario al costo efectivo de la factura; CxP intacta.
*/

beforeEach(function (): void {
    foreach ([Roles::BODEGUERO, Roles::RECEPCION, Roles::ENCARGADO_OBRA, Roles::GERENCIA] as $rol) {
        Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
    }

    Permission::findOrCreate(Permisos::VERIFICAR_RECEPCION_COMPRA, 'web');
    Permission::findOrCreate(Permisos::CORREGIR_RECEPCION_COMPRA, 'web');
    Permission::findOrCreate(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO, 'web');

    Role::findByName(Roles::BODEGUERO, 'web')->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);
    Role::findByName(Roles::ENCARGADO_OBRA, 'web')->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);
    Role::findByName(Roles::GERENCIA, 'web')->givePermissionTo(Permisos::CORREGIR_RECEPCION_COMPRA);

    $this->registrar = app(MarcarPorRecibirService::class);
    $this->verificar = app(VerificarRecepcionService::class);
    $this->corregir = app(CorregirRecepcionService::class);

    $this->bodega = Bodega::factory()->create();

    $this->bodeguero = User::factory()->create(['is_active' => true]);
    $this->bodeguero->assignRole(Roles::BODEGUERO);
    $this->bodeguero->bodegas()->attach($this->bodega);

    $this->gerente = User::factory()->create(['is_active' => true]);
    $this->gerente->assignRole(Roles::GERENCIA);

    $this->registrador = User::factory()->create(['is_active' => true]);
    $this->registrador->givePermissionTo(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO);
});

/**
 * Compra a la bodega del test: 100 unidades @ L.10 netos, sin ISV.
 *
 * @return array{0: Compra, 1: CompraLinea, 2: Material}
 */
function montarCompraDeCien(Bodega $bodega): array
{
    $material = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($bodega)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    $linea = CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 100, 'costo_unitario' => 10,
    ]);

    return [$compra, $linea, $material];
}

test('GOLDEN: contó de MENOS (90 reales 100) — la corrección mete la diferencia al costo de factura', function (): void {
    [$compra, $linea, $material] = montarCompraDeCien($this->bodega);

    $this->registrar->registrar($compra, $this->registrador->id);
    $this->verificar->verificar($compra->fresh(), [$linea->id => '90'], $this->bodeguero);

    // Confirmada con 90 @ 10 = 900.
    $stock = Existencia::query()
        ->where('material_id', $material->id)
        ->where('bodega_id', $this->bodega->id)
        ->firstOrFail();

    expect($stock->cantidad)->toBe('90.0000')->and($stock->valor_total)->toBe('900.00');

    // Gerencia corrige: en realidad llegaron las 100.
    $this->corregir->corregir($compra->fresh(), [$linea->id => '100'], 'Conteo del lote incompleto.', $this->gerente);

    $stock->refresh();
    $linea->refresh();

    expect($stock->cantidad)->toBe('100.0000')
        ->and($stock->valor_total)->toBe('1000.00')
        ->and($linea->cantidad_recibida)->toBe('100.0000')
        ->and($linea->tieneDiferencia())->toBeFalse()
        ->and($linea->verificada_por)->toBe($this->gerente->id);
});

test('contó de MÁS (100 reales 95) — la corrección retira el valor EXACTO, no el promedio', function (): void {
    [$compra, $linea, $material] = montarCompraDeCien($this->bodega);

    $this->registrar->registrar($compra, $this->registrador->id);
    $this->verificar->verificar($compra->fresh(), [$linea->id => '100'], $this->bodeguero);

    $this->corregir->corregir($compra->fresh(), [$linea->id => '95'], 'Se contaron cajas vacías.', $this->gerente);

    $stock = Existencia::query()
        ->where('material_id', $material->id)
        ->where('bodega_id', $this->bodega->id)
        ->firstOrFail();

    expect($stock->cantidad)->toBe('95.0000')
        ->and($stock->valor_total)->toBe('950.00')
        ->and($linea->fresh()->tieneDiferencia())->toBeTrue(); // 100 facturadas vs 95: reclamo visible
});

test('confirmada SIN el permiso Corregir recepción → bloqueada (aunque pueda verificar)', function (): void {
    [$compra, $linea] = montarCompraDeCien($this->bodega);

    $this->registrar->registrar($compra, $this->registrador->id);
    $this->verificar->verificar($compra->fresh(), [$linea->id => '90'], $this->bodeguero);

    // El bodeguero verificó — pero corregir YA CONFIRMADA mueve stock: no.
    expect(fn () => $this->corregir->corregir($compra->fresh(), [$linea->id => '100'], 'Me equivoqué.', $this->bodeguero))
        ->toThrow(CompraNoCorregibleException::class, 'Corregir recepción');
});

test('mientras está Por recibir, quien verificó corrige su línea sin mover stock', function (): void {
    $material = Material::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();

    $encargado = User::factory()->create(['is_active' => true]);
    $encargado->assignRole(Roles::ENCARGADO_OBRA);
    $obra->encargados()->attach($encargado->id);

    // Mixta: la porción de obra queda pendiente → la compra NO confirma.
    $compra = Compra::factory()->paraBodega($this->bodega)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    $lineaBodega = CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 100, 'costo_unitario' => 10,
    ]);
    CompraLinea::factory()->create([
        'compra_id'   => $compra->id, 'material_id' => $material->id,
        'cantidad'    => 50, 'costo_unitario' => 10,
        'proyecto_id' => $obra->id,
    ]);

    $this->registrar->registrar($compra, $this->registrador->id);
    $this->verificar->verificar($compra->fresh(), [$lineaBodega->id => '90'], $this->bodeguero);

    // El bodeguero se dio cuenta del error ANTES de que la compra confirme.
    $this->corregir->corregir($compra->fresh(), [$lineaBodega->id => '98'], 'Conté un tarimado de menos.', $this->bodeguero);

    expect($lineaBodega->fresh()->cantidad_recibida)->toBe('98.0000')
        ->and($compra->fresh()->estado)->toBe(EstadoCompra::PorRecibir)
        ->and(Existencia::query()->where('material_id', $material->id)->exists())->toBeFalse();
});

test('la requisición enlazada ajusta su despacho con el conteo corregido', function (): void {
    $cemento = Material::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();

    $encargado = User::factory()->create(['is_active' => true]);
    $encargado->assignRole(Roles::ENCARGADO_OBRA);
    $obra->encargados()->attach($encargado->id);

    $requisicion = Requisicion::factory()
        ->paraProyecto($obra)
        ->enEstado(EstadoRequisicion::RequisicionCompra)
        ->create();
    $reqLinea = RequisicionLinea::factory()->paraMaterial($cemento)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '100.0000',
        'cantidad_autorizada' => '100.0000',
    ]);

    $compra = Compra::factory()
        ->directaAObra($obra)
        ->paraRequisicion($requisicion)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    $linea = CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $cemento->id,
        'cantidad'  => 100, 'costo_unitario' => 220,
    ]);

    $this->registrar->registrar($compra, $this->registrador->id);
    $this->verificar->verificar($compra->fresh(), [$linea->id => '80'], $encargado);

    expect($reqLinea->fresh()->cantidad_despachada)->toBe('80.0000');

    // El camión de la tarde trajo el resto: eran 100.
    $this->corregir->corregir($compra->fresh(), [$linea->id => '100'], 'Segunda entrega del proveedor.', $this->gerente);

    expect($reqLinea->fresh()->cantidad_despachada)->toBe('100.0000')
        ->and(Existencia::query()
            ->where('material_id', $cemento->id)
            ->where('proyecto_id', $obra->id)
            ->value('cantidad'))->toBe('100.0000');
});
