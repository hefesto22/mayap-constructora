<?php

declare(strict_types=1);

use App\Models\Bodega;
use App\Models\Compra;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\MovimientoInventario;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Visibilidad por bodega (Fase 2).
|--------------------------------------------------------------------------
| Un usuario asignado a la bodega A NO ve el stock, compras ni movimientos
| de la bodega B. Sí ve el stock en obra (decisión de producto). Quien tiene
| el permiso `ver_todas_las_bodegas` ve todo.
*/

beforeEach(function (): void {
    Permission::findOrCreate('ver_todas_las_bodegas', 'web');

    $this->bodegaA = Bodega::factory()->create(['nombre' => 'BODEGA A']);
    $this->bodegaB = Bodega::factory()->create(['nombre' => 'BODEGA B']);
    $this->proyecto = Proyecto::factory()->create();
    $this->material = Material::factory()->create();

    $this->exA = Existencia::factory()->enBodega($this->bodegaA)->create(['material_id' => $this->material->id]);
    $this->exB = Existencia::factory()->enBodega($this->bodegaB)->create(['material_id' => $this->material->id]);
    $this->exObra = Existencia::factory()->enObra($this->proyecto)->create(['material_id' => $this->material->id]);
});

test('usuario restringido ve el stock de sus bodegas y el de obra, no el de otra bodega', function (): void {
    $user = User::factory()->create();
    $user->bodegas()->attach($this->bodegaA);

    $ids = Existencia::query()->visibleParaUsuario($user)->pluck('id')->all();

    expect($ids)->toContain($this->exA->id)
        ->and($ids)->toContain($this->exObra->id)
        ->and($ids)->not->toContain($this->exB->id);
});

test('usuario con permiso ver_todas_las_bodegas ve todas las existencias', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('ver_todas_las_bodegas');

    expect(Existencia::query()->visibleParaUsuario($user)->count())->toBe(3)
        ->and($user->puedeVerTodasLasBodegas())->toBeTrue();
});

test('usuario sin bodegas asignadas no ve stock de ninguna bodega (solo obra)', function (): void {
    $user = User::factory()->create();

    $ids = Existencia::query()->visibleParaUsuario($user)->pluck('id')->all();

    expect($ids)->toContain($this->exObra->id)
        ->and($ids)->not->toContain($this->exA->id)
        ->and($ids)->not->toContain($this->exB->id);
});

test('usuario restringido solo ve las compras de sus bodegas', function (): void {
    $compraA = Compra::factory()->paraBodega($this->bodegaA)->create();
    $compraB = Compra::factory()->paraBodega($this->bodegaB)->create();

    $user = User::factory()->create();
    $user->bodegas()->attach($this->bodegaA);

    $ids = Compra::query()->visibleParaUsuario($user)->pluck('id')->all();

    expect($ids)->toContain($compraA->id)
        ->and($ids)->not->toContain($compraB->id);
});

test('usuario restringido solo ve movimientos que tocan sus bodegas', function (): void {
    $service = new RegistrarMovimientoService;
    $service->entradaCompra($this->material->id, Ubicacion::bodega($this->bodegaA->id), '10', '5');
    $service->entradaCompra($this->material->id, Ubicacion::bodega($this->bodegaB->id), '10', '5');

    $user = User::factory()->create();
    $user->bodegas()->attach($this->bodegaA);

    $destinos = MovimientoInventario::query()->visibleParaUsuario($user)->pluck('bodega_destino_id')->all();

    expect($destinos)->toContain($this->bodegaA->id)
        ->and($destinos)->not->toContain($this->bodegaB->id);
});

test('la relación bodegas del usuario es muchos-a-muchos', function (): void {
    $user = User::factory()->create();
    $user->bodegas()->attach([$this->bodegaA->id, $this->bodegaB->id]);

    expect($user->bodegas()->count())->toBe(2)
        ->and($user->bodegasAsignadasIds())->toContain($this->bodegaA->id, $this->bodegaB->id)
        ->and($this->bodegaA->users()->count())->toBe(1);
});
