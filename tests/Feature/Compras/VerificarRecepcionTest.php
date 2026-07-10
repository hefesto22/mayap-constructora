<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Enums\EstadoRequisicion;
use App\Exceptions\Compras\CompraNoVerificableException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\CuentaPorPagar;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\User;
use App\Services\Compras\MarcarPorRecibirService;
use App\Services\Compras\VerificarRecepcionService;
use App\Support\Permisos;
use App\Support\Roles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| G2 — Verificación de recepción de compras.
|--------------------------------------------------------------------------
| El stock entra cuando el punto de llegada VERIFICA, no al registrar.
| Cada rol cuenta SU porción; la CxP se crea por lo FACTURADO.
*/

beforeEach(function (): void {
    foreach ([Roles::BODEGUERO, Roles::RECEPCION, Roles::ENCARGADO_OBRA, Roles::GERENCIA] as $rol) {
        Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
    }

    // La verificación es por PERMISO (+ alcance): bodeguero y encargado lo
    // tienen de fábrica; recepción NO (quien compra no se auto-valida).
    Permission::findOrCreate(Permisos::VERIFICAR_RECEPCION_COMPRA, 'web');
    Role::findByName(Roles::BODEGUERO, 'web')->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);
    Role::findByName(Roles::ENCARGADO_OBRA, 'web')->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);

    $this->registrar = app(MarcarPorRecibirService::class);
    $this->verificar = app(VerificarRecepcionService::class);
    $this->bodega = Bodega::factory()->create();

    $this->bodeguero = User::factory()->create(['is_active' => true]);
    $this->bodeguero->assignRole(Roles::BODEGUERO);
    $this->bodeguero->bodegas()->attach($this->bodega);

    // Quien registra estas compras de prueba puede comprar fuera de
    // presupuesto (aquí el foco es la VERIFICACIÓN, no el presupuesto).
    Permission::findOrCreate(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO, 'web');
    $this->registrador = User::factory()->create(['is_active' => true]);
    $this->registrador->givePermissionTo(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO);
});

test('GOLDEN: registrar avisa, verificar completo confirma y el stock entra por lo RECIBIDO', function (): void {
    $material = Material::factory()->create();
    $proveedor = Proveedor::factory()->aCredito(30)->create();

    $compra = Compra::factory()
        ->paraBodega($this->bodega)
        ->paraProveedor($proveedor)
        ->aCredito()
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    $linea = CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 100, 'costo_unitario' => 10, // facturado: 100 × 10 = 1,000
    ]);

    $this->registrar->registrar($compra, $this->registrador->id);

    // Registrada: campanita al bodeguero, sin stock ni CxP todavía.
    expect($compra->fresh()->estado)->toBe(EstadoCompra::PorRecibir)
        ->and($this->bodeguero->notifications()->count())->toBe(1)
        ->and(Existencia::query()->where('material_id', $material->id)->exists())->toBeFalse()
        ->and(CuentaPorPagar::query()->where('compra_id', $compra->id)->exists())->toBeFalse();

    // El bodeguero cuenta: llegaron solo 90 de las 100 facturadas.
    $estado = $this->verificar->verificar($compra->fresh(), [$linea->id => '90'], $this->bodeguero);

    expect($estado)->toBe(EstadoCompra::Confirmada);

    // Stock por lo RECIBIDO (90 @ 10 = 900)...
    $stock = Existencia::query()
        ->where('material_id', $material->id)
        ->where('bodega_id', $this->bodega->id)
        ->firstOrFail();

    expect($stock->cantidad)->toBe('90.0000')
        ->and($stock->valor_total)->toBe('900.00');

    // ...pero la CxP por lo FACTURADO (decisión de negocio: la factura es
    // la deuda legal; las 10 faltantes son reclamo al proveedor).
    expect((string) CuentaPorPagar::query()->where('compra_id', $compra->id)->value('monto_original'))->toBe('1000.00')
        ->and($linea->fresh()->tieneDiferencia())->toBeTrue();
});

test('en compra mixta cada rol verifica SU porción y la compra confirma al completarse', function (): void {
    $material = Material::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();

    $encargado = User::factory()->create(['is_active' => true]);
    $encargado->assignRole(Roles::ENCARGADO_OBRA);
    $obra->encargados()->attach($encargado->id);

    $compra = Compra::factory()->paraBodega($this->bodega)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    $lineaBodega = CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 100, 'costo_unitario' => 10,
    ]);
    $lineaObra = CompraLinea::factory()->create([
        'compra_id'   => $compra->id, 'material_id' => $material->id,
        'cantidad'    => 50, 'costo_unitario' => 10,
        'proyecto_id' => $obra->id,
    ]);

    $this->registrar->registrar($compra, $this->registrador->id);

    // El bodeguero NO puede contar la porción de la obra...
    expect(fn () => $this->verificar->verificar($compra->fresh(), [$lineaObra->id => '50'], $this->bodeguero))
        ->toThrow(CompraNoVerificableException::class, 'destino');

    // ...cada quien lo suyo: bodeguero primero (queda parcial)...
    $estado = $this->verificar->verificar($compra->fresh(), [$lineaBodega->id => '100'], $this->bodeguero);
    expect($estado)->toBe(EstadoCompra::PorRecibir);

    // ...encargado después → completa y confirma.
    $estado = $this->verificar->verificar($compra->fresh(), [$lineaObra->id => '50'], $encargado);
    expect($estado)->toBe(EstadoCompra::Confirmada);

    $enObra = Existencia::query()
        ->where('material_id', $material->id)
        ->where('proyecto_id', $obra->id)
        ->firstOrFail();

    expect($enObra->cantidad)->toBe('50.0000');
});

test('recepción NO puede verificar (quien compra no se auto-valida); gerencia sí como respaldo', function (): void {
    $material = Material::factory()->create();

    $recepcion = User::factory()->create(['is_active' => true]);
    $recepcion->assignRole(Roles::RECEPCION);

    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->assignRole(Roles::GERENCIA);

    $compra = Compra::factory()->paraBodega($this->bodega)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    $linea = CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 10, 'costo_unitario' => 100,
    ]);

    $this->registrar->registrar($compra, $this->registrador->id);

    expect(fn () => $this->verificar->verificar($compra->fresh(), [$linea->id => '10'], $recepcion))
        ->toThrow(CompraNoVerificableException::class);

    expect($this->verificar->verificar($compra->fresh(), [$linea->id => '10'], $gerente))
        ->toBe(EstadoCompra::Confirmada);
});

test('la requisición enlazada despacha lo RECIBIDO, no lo facturado', function (): void {
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

    // Solo las 80 que llegaron cuentan como despachadas.
    expect($reqLinea->fresh()->cantidad_despachada)->toBe('80.0000');
});

test('no se re-verifica una línea ni se verifica una compra que no está Por recibir', function (): void {
    $material = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($this->bodega)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    $linea = CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 10, 'costo_unitario' => 100,
    ]);

    // Aún en borrador → no se verifica.
    expect(fn () => $this->verificar->verificar($compra, [$linea->id => '10'], $this->bodeguero))
        ->toThrow(CompraNoVerificableException::class, 'Borrador');

    $this->registrar->registrar($compra, $this->registrador->id);
    $this->verificar->verificar($compra->fresh(), [$linea->id => '10'], $this->bodeguero);

    // Ya confirmada → tampoco.
    expect(fn () => $this->verificar->verificar($compra->fresh(), [$linea->id => '10'], $this->bodeguero))
        ->toThrow(CompraNoVerificableException::class);
});
