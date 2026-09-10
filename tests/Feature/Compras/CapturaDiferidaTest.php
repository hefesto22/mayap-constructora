<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Exceptions\Compras\CompraNoVerificableException;
use App\Models\Compra;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Compras\CapturarRecepcionService;
use App\Services\Compras\MarcarPorRecibirService;
use App\Support\Permisos;
use App\Support\Roles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| VÍA RÁPIDA DE COMPRAS (Mauricio 2026-09-05, extendida a obra 2026-09-10).
|--------------------------------------------------------------------------
| "Cuando va directo a obra, en vez de llenar todo eso, solo que suba la
| foto de lo que se compró... así evitamos eso de que estén agregando y
| agregando, que eso lleva mucho tiempo."
|
| Quien registra pone TOTAL + FOTO. El detalle lo escribe quien recibe, con
| la mercadería enfrente. La red que sostiene ese vacío es el cuadre: si lo
| anotado no suma lo que dice la factura, NADA entra al inventario.
*/

beforeEach(function (): void {
    foreach ([Roles::BODEGUERO, Roles::ENCARGADO_OBRA] as $rol) {
        Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
    }

    Permission::findOrCreate(Permisos::VERIFICAR_RECEPCION_COMPRA, 'web');
    Role::findByName(Roles::ENCARGADO_OBRA, 'web')->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);
    Role::findByName(Roles::BODEGUERO, 'web')->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);

    Permission::findOrCreate(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO, 'web');

    $this->registrar = app(MarcarPorRecibirService::class);
    $this->capturar = app(CapturarRecepcionService::class);

    $this->obra = Proyecto::factory()->create();

    $this->encargado = User::factory()->create(['is_active' => true]);
    $this->encargado->assignRole(Roles::ENCARGADO_OBRA);
    $this->obra->encargados()->attach($this->encargado);

    $this->registrador = User::factory()->create(['is_active' => true]);
    $this->registrador->givePermissionTo(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO);

    $this->compraExpres = function (array $extra = []): Compra {
        return Compra::factory()
            ->directaAObra($this->obra)
            ->create(array_merge([
                'aplica_isv'     => false,
                'isv_porcentaje' => 0,
                'total_factura'  => '5000.00',
                'fotos_factura'  => ['facturas/2026-09/factura.webp'],
            ], $extra));
    };
});

test('GOLDEN: la compra directo a obra se registra con total y foto, y el encargado escribe el detalle al recibirla', function (): void {
    $compra = ($this->compraExpres)();

    expect($compra->capturaDiferida())->toBeTrue();

    $this->registrar->registrar($compra, $this->registrador->id);

    $compra->refresh();

    expect($compra->estado)->toBe(EstadoCompra::PorRecibir)
        ->and($compra->lineas)->toHaveCount(0)
        ->and($compra->esperandoCaptura())->toBeTrue();

    // El camión llegó a la obra: el encargado anota lo que le bajaron.
    $cemento = Material::factory()->create();
    $arena = Material::factory()->create();

    $estado = $this->capturar->capturar($compra, [
        ['material_id' => $cemento->id, 'cantidad' => '100', 'precio_factura' => '35'],
        ['material_id' => $arena->id, 'cantidad' => '50', 'precio_factura' => '30'],
    ], $this->encargado);

    expect($estado)->toBe(EstadoCompra::Confirmada);

    $compra->refresh()->load('lineas');

    expect($compra->lineas)->toHaveCount(2)
        ->and($compra->totalesCoinciden())->toBeTrue();

    // Y el material quedó en la existencia de LA OBRA, no de una bodega.
    $stockObra = Existencia::query()
        ->where('material_id', $cemento->id)
        ->where('proyecto_id', $this->obra->id)
        ->value('cantidad');

    expect((string) $stockObra)->toBe('100.0000');
});

test('si lo anotado no cuadra con la factura, nada entra al inventario', function (): void {
    $compra = ($this->compraExpres)();
    $this->registrar->registrar($compra, $this->registrador->id);

    $material = Material::factory()->create();

    // 100 × 35 = 3 500, y la factura dice 5 000.
    expect(fn () => $this->capturar->capturar($compra, [
        ['material_id' => $material->id, 'cantidad' => '100', 'precio_factura' => '35'],
    ], $this->encargado))->toThrow(CompraNoVerificableException::class);

    $compra->refresh()->load('lineas');

    expect($compra->estado)->toBe(EstadoCompra::PorRecibir)
        ->and($compra->lineas)->toHaveCount(0)
        ->and(Existencia::query()->where('material_id', $material->id)->exists())->toBeFalse();
});

test('el detalle lo escribe QUIEN RECIBE: un encargado de otra obra no alcanza', function (): void {
    $compra = ($this->compraExpres)();
    $this->registrar->registrar($compra, $this->registrador->id);

    $ajeno = User::factory()->create(['is_active' => true]);
    $ajeno->assignRole(Roles::ENCARGADO_OBRA);
    Proyecto::factory()->create()->encargados()->attach($ajeno);

    $material = Material::factory()->create();

    expect(fn () => $this->capturar->capturar($compra, [
        ['material_id' => $material->id, 'cantidad' => '100', 'precio_factura' => '50'],
    ], $ajeno))->toThrow(CompraNoVerificableException::class);
});

test('sin documento fiscal la vía rápida no se registra: se confirmaría sola y tronaría días después', function (): void {
    $compra = ($this->compraExpres)(['tipo_documento_fiscal' => null]);

    expect(fn () => $this->registrar->registrar($compra, $this->registrador->id))
        ->toThrow(CompraNoConfirmableException::class);

    expect($compra->fresh()->estado)->toBe(EstadoCompra::Borrador);
});

test('sin foto de factura la vía rápida no se registra: es el único respaldo de lo comprado', function (): void {
    $compra = ($this->compraExpres)(['fotos_factura' => []]);

    expect(fn () => $this->registrar->registrar($compra, $this->registrador->id))
        ->toThrow(CompraNoConfirmableException::class);
});

test('un repuesto de reparación NO usa la vía rápida: su detalle amarra el gasto a la máquina', function (): void {
    $compra = Compra::factory()->create(['mantenimiento_id' => null]);

    expect($compra->capturaDiferida())->toBeTrue();

    // Con mantenimiento, el detalle se escribe al registrar.
    $compra->mantenimiento_id = 1;

    expect($compra->capturaDiferida())->toBeFalse();
});
