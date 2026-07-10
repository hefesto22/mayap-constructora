<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;
use App\Exceptions\Requisiciones\TransicionInvalidaException;
use App\Models\Bodega;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\User;
use App\Services\Inventario\Ubicacion;
use App\Services\Requisiciones\NotificadorRequisiciones;
use App\Services\Requisiciones\TransicionarRequisicionService;
use App\Support\Roles;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Campanitas (database notifications) del flujo de requisiciones.
|--------------------------------------------------------------------------
| El sistema avisa al rol que tiene el SIGUIENTE paso; el actor de la
| transición nunca se auto-notifica.
*/

beforeEach(function (): void {
    foreach ([Roles::BODEGUERO, Roles::RECEPCION, Roles::ENCARGADO_OBRA] as $rol) {
        Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
    }

    $this->service = app(TransicionarRequisicionService::class);
    $this->proyecto = Proyecto::factory()->create();
});

function usuarioConRol(string $rol): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($rol);

    return $user;
}

test('al autorizar, la campanita llega a los encargados de la obra', function (): void {
    $bodeguero = usuarioConRol(Roles::BODEGUERO);
    $encargado = usuarioConRol(Roles::ENCARGADO_OBRA);
    $this->proyecto->encargados()->attach($encargado->id);

    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    RequisicionLinea::factory()->paraMaterial(Material::factory()->create())->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '10.0000',
    ]);

    $this->service->autorizar($requisicion, userId: $bodeguero->id);

    expect($encargado->notifications()->count())->toBe(1)
        ->and(json_encode($encargado->notifications()->first()?->data))->toContain('autorizada')
        ->and($bodeguero->notifications()->count())->toBe(0);
});

test('sin stock para despachar, la campanita llega a recepción (compras)', function (): void {
    $bodeguero = usuarioConRol(Roles::BODEGUERO);
    $recepcion = usuarioConRol(Roles::RECEPCION);

    $bodega = Bodega::factory()->create();
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    RequisicionLinea::factory()->paraMaterial(Material::factory()->create())->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '10.0000',
    ]);

    $this->service->autorizar($requisicion, userId: $bodeguero->id);

    $requisicion->refresh();
    $this->service->despachar($requisicion, Ubicacion::bodega($bodega->id), $bodeguero->id);

    expect($recepcion->notifications()->count())->toBe(1)
        ->and(json_encode($recepcion->notifications()->first()?->data))->toContain('sin stock');
});

test('la nueva solicitud avisa a bodega y el actor no se auto-notifica', function (): void {
    $bodegueroActor = usuarioConRol(Roles::BODEGUERO);
    $bodegueroTurno = usuarioConRol(Roles::BODEGUERO);

    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();

    app(NotificadorRequisiciones::class)->nuevaSolicitud($requisicion, $bodegueroActor->id);

    expect($bodegueroTurno->notifications()->count())->toBe(1)
        ->and($bodegueroActor->notifications()->count())->toBe(0);
});

test('requisición que EXCEDE el presupuesto alerta a gerencia y bodegueros con el detalle', function (): void {
    Role::firstOrCreate(['name' => Roles::GERENCIA, 'guard_name' => 'web']);

    $solicitante = usuarioConRol(Roles::ENCARGADO_OBRA);
    $gerente = usuarioConRol(Roles::GERENCIA);
    $bodeguero = usuarioConRol(Roles::BODEGUERO);

    // Material SIN presupuesto en la obra: pedir 5 = exceso total.
    $material = Material::factory()->create(['nombre' => 'CASQUETE HF']);

    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    RequisicionLinea::factory()->paraMaterial($material)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '5.0000',
    ]);

    app(NotificadorRequisiciones::class)->nuevaSolicitud($requisicion, $solicitante->id);

    // Gerencia: SOLO la alerta de exceso. Bodeguero: la de "por autorizar"
    // + la de exceso. El solicitante no se auto-notifica.
    expect($gerente->notifications()->count())->toBe(1)
        ->and(json_encode($gerente->notifications()->first()?->data))
        ->toContain('EXCEDE')
        ->toContain('CASQUETE HF')
        ->and($bodeguero->notifications()->count())->toBe(2)
        ->and($solicitante->notifications()->count())->toBe(0);
});

test('reintentar despachar SIN stock nuevo no revienta: sigue en Requisición de compra', function (): void {
    $bodeguero = usuarioConRol(Roles::BODEGUERO);

    $bodega = Bodega::factory()->create();
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();
    RequisicionLinea::factory()->paraMaterial(Material::factory()->create())->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '10.0000',
    ]);

    $this->service->autorizar($requisicion, userId: $bodeguero->id);
    $requisicion->refresh();

    // Primer despacho sin stock → Requisición de compra (correcto).
    $this->service->despachar($requisicion, Ubicacion::bodega($bodega->id), $bodeguero->id);
    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::RequisicionCompra);

    // Segundo intento sin stock: NADA de 500 — se queda donde está.
    $this->service->despachar($requisicion->fresh(), Ubicacion::bodega($bodega->id), $bodeguero->id);
    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::RequisicionCompra);
});

test('una transición fallida no deja campanitas fantasma (transaccional)', function (): void {
    $encargado = usuarioConRol(Roles::ENCARGADO_OBRA);
    $this->proyecto->encargados()->attach($encargado->id);

    // Solicitada NO puede saltar directo a EnTransito → excepción.
    $requisicion = Requisicion::factory()->paraProyecto($this->proyecto)->create();

    expect(fn () => $this->service->marcarEnTransito($requisicion))
        ->toThrow(TransicionInvalidaException::class)
        ->and($encargado->notifications()->count())->toBe(0);
});
