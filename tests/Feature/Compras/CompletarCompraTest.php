<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoAnulableException;
use App\Exceptions\Compras\CompraNoCompletableException;
use App\Exceptions\Compras\CompraNoCorregibleException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Material;
use App\Models\User;
use App\Services\Compras\AnularCompraService;
use App\Services\Compras\CompletarCompraService;
use App\Services\Compras\CorregirRecepcionService;
use App\Services\Compras\MarcarPorRecibirService;
use App\Services\Compras\VerificarRecepcionService;
use App\Support\Permisos;
use App\Support\Roles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Cierre de compras (Completada) — la conciliación final.
|--------------------------------------------------------------------------
| Cuadrada (facturado = recibido) corre la ventana de corrección (24 h);
| vencida, se COMPLETA y queda sellada. Con diferencias jamás se completa
| y la corrección no expira.
*/

beforeEach(function (): void {
    foreach ([Roles::BODEGUERO, Roles::RECEPCION, Roles::GERENCIA] as $rol) {
        Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
    }

    foreach ([
        Permisos::VERIFICAR_RECEPCION_COMPRA,
        Permisos::CORREGIR_RECEPCION_COMPRA,
        Permisos::COMPLETAR_COMPRA,
        Permisos::COMPRAR_FUERA_DE_PRESUPUESTO,
    ] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }

    Role::findByName(Roles::BODEGUERO, 'web')->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);
    Role::findByName(Roles::GERENCIA, 'web')
        ->givePermissionTo([Permisos::CORREGIR_RECEPCION_COMPRA, Permisos::COMPLETAR_COMPRA]);

    $this->registrar = app(MarcarPorRecibirService::class);
    $this->verificar = app(VerificarRecepcionService::class);
    $this->corregir = app(CorregirRecepcionService::class);
    $this->completar = app(CompletarCompraService::class);

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
 * Compra de 100 unidades @ L.10 a la bodega del test, registrada.
 *
 * @return array{0: Compra, 1: CompraLinea}
 */
function compraRegistradaDeCien(Bodega $bodega, MarcarPorRecibirService $registrar, User $registrador): array
{
    $material = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($bodega)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    $linea = CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 100, 'costo_unitario' => 10,
    ]);

    $registrar->registrar($compra, $registrador->id);

    return [$compra, $linea];
}

test('GOLDEN: cuadrada → ventana de 24h → completar sella la compra (ni corregir ni anular)', function (): void {
    [$compra, $linea] = compraRegistradaDeCien($this->bodega, $this->registrar, $this->registrador);

    // Llegó todo: cuadra y confirma.
    $this->verificar->verificar($compra->fresh(), [$linea->id => '100'], $this->bodeguero);

    // Ventana abierta: aún NO se puede completar...
    expect(fn () => $this->completar->completar($compra->fresh(), $this->gerente))
        ->toThrow(CompraNoCompletableException::class, 'ventana');

    // ...pero corregir sí (dentro de las 24 h).
    expect($this->corregir->lineasCorregiblesPara($this->gerente, $compra->fresh()))->toHaveCount(1);

    $this->travel(25)->hours();

    // Vencida la ventana: corregir se apaga y completar se enciende.
    expect($this->corregir->lineasCorregiblesPara($this->gerente, $compra->fresh()))->toBeEmpty();

    $this->completar->completar($compra->fresh(), $this->gerente);

    $compra->refresh();

    expect($compra->estado)->toBe(EstadoCompra::Completada)
        ->and($compra->completada_at)->not->toBeNull()
        ->and($compra->completada_por)->toBe($this->gerente->id);

    // Sellada: ni corregir ni anular.
    expect(fn () => $this->corregir->corregir($compra->fresh(), [$linea->id => '99'], 'Tarde.', $this->gerente))
        ->toThrow(CompraNoCorregibleException::class);

    expect(fn () => app(AnularCompraService::class)->anular($compra->fresh(), 'Ya no.', $this->gerente->id))
        ->toThrow(CompraNoAnulableException::class);
});

test('con DIFERENCIAS jamás se completa y la corrección NO expira', function (): void {
    [$compra, $linea] = compraRegistradaDeCien($this->bodega, $this->registrar, $this->registrador);

    // Llegaron 90 de 100: queda el reclamo abierto.
    $this->verificar->verificar($compra->fresh(), [$linea->id => '90'], $this->bodeguero);

    $this->travel(48)->hours();

    expect(fn () => $this->completar->completar($compra->fresh(), $this->gerente))
        ->toThrow(CompraNoCompletableException::class, 'NO cuadra');

    // Dos días después la corrección sigue viva — hasta resolver.
    expect($this->corregir->lineasCorregiblesPara($this->gerente, $compra->fresh()))->toHaveCount(1);
});

test('corregir dentro de la ventana REINICIA el reloj de cierre', function (): void {
    [$compra, $linea] = compraRegistradaDeCien($this->bodega, $this->registrar, $this->registrador);

    // 90 de 100 (diferencia) → 30 h después el proveedor completa la entrega.
    $this->verificar->verificar($compra->fresh(), [$linea->id => '90'], $this->bodeguero);
    $this->travel(30)->hours();
    $this->corregir->corregir($compra->fresh(), [$linea->id => '100'], 'Segunda entrega del proveedor.', $this->gerente);

    // Acaba de cuadrar: la ventana corre desde la CORRECCIÓN, no desde antes.
    expect(fn () => $this->completar->completar($compra->fresh(), $this->gerente))
        ->toThrow(CompraNoCompletableException::class, 'ventana');

    $this->travel(25)->hours();

    $this->completar->completar($compra->fresh(), $this->gerente);

    expect($compra->fresh()->estado)->toBe(EstadoCompra::Completada);
});

test('completar exige el permiso (bodeguero no puede aunque la compra esté lista)', function (): void {
    [$compra, $linea] = compraRegistradaDeCien($this->bodega, $this->registrar, $this->registrador);

    $this->verificar->verificar($compra->fresh(), [$linea->id => '100'], $this->bodeguero);
    $this->travel(25)->hours();

    expect(fn () => $this->completar->completar($compra->fresh(), $this->bodeguero))
        ->toThrow(CompraNoCompletableException::class, 'permiso');
});
