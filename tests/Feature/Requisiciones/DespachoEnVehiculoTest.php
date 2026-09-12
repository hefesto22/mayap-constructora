<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Maquina;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Requisiciones\TransicionarRequisicionService;

/*
|--------------------------------------------------------------------------
| EL MATERIAL VIAJA EN UN CAMIÓN NUESTRO (Mauricio 2026-09-10).
|--------------------------------------------------------------------------
| "Los materiales que salen de bodega salen en volquetas... se cargan y
| salen a dejarlo y regresan uno o dos días después."
|
| Antes el despacho movía el stock de bodega a la obra en el MISMO
| instante: durante dos días el sistema juraba que la obra tenía material
| que iba rodando. Con el camión declarado, el stock está donde está el
| material — arriba del camión— hasta que la obra confirma.
*/

beforeEach(function (): void {
    $this->inventario = app(RegistrarMovimientoService::class);
    $this->service = app(TransicionarRequisicionService::class);

    $this->bodega = Bodega::factory()->create();
    $this->obra = Proyecto::factory()->create();
    $this->cemento = Material::factory()->create();

    $this->camion = Maquina::factory()->create();
    $this->volqueta = Bodega::factory()->create([
        'nombre'      => 'VOLQUETA DE REPARTO',
        'movil'       => true,
        'maquina_id'  => $this->camion->id,
        'material_id' => null,
    ]);

    $this->inventario->entradaCompra(
        $this->cemento->id,
        Ubicacion::bodega($this->bodega->id),
        '100',
        '250',
    );

    $this->pedido = function (string $cantidad = '40'): Requisicion {
        $requisicion = Requisicion::factory()->paraProyecto($this->obra)->create();
        RequisicionLinea::factory()->create([
            'requisicion_id'      => $requisicion->id,
            'material_id'         => $this->cemento->id,
            'cantidad_solicitada' => $cantidad,
        ]);
        $this->service->autorizar($requisicion);

        return $requisicion->refresh();
    };
});

$stockEn = function (string $columna, int $id, int $materialId): string {
    $cantidad = Existencia::query()
        ->where($columna, $id)
        ->where('material_id', $materialId)
        ->value('cantidad');

    return is_numeric($cantidad) ? (string) $cantidad : '0';
};

test('GOLDEN: con camión declarado el material queda EN el camión, no en la obra', function () use ($stockEn): void {
    $requisicion = ($this->pedido)();

    $this->service->despachar(
        $requisicion,
        Ubicacion::bodega($this->bodega->id),
        vehiculo: $this->volqueta,
    );

    expect($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Despachada)
        ->and($requisicion->fresh()->vehiculo_id)->toBe($this->volqueta->id);

    // El cemento salió de bodega...
    expect($stockEn('bodega_id', $this->bodega->id, $this->cemento->id))->toBe('60.0000');

    // ...y está ARRIBA DEL CAMIÓN, no en la obra. Esto es lo que antes
    // mentía: la obra figuraba con material que iba en la carretera.
    expect($stockEn('bodega_id', $this->volqueta->id, $this->cemento->id))->toBe('40.0000')
        ->and($stockEn('proyecto_id', $this->obra->id, $this->cemento->id))->toBe('0');
});

test('al confirmar la obra, el material baja del camión y recién ahí llega', function () use ($stockEn): void {
    $requisicion = ($this->pedido)();

    $this->service->despachar($requisicion, Ubicacion::bodega($this->bodega->id), vehiculo: $this->volqueta);
    $this->service->marcarEnTransito($requisicion->refresh());
    $this->service->recibir($requisicion->refresh());

    expect($stockEn('proyecto_id', $this->obra->id, $this->cemento->id))->toBe('40.0000')
        ->and($stockEn('bodega_id', $this->volqueta->id, $this->cemento->id))->toBe('0.0000');
});

test('lo que la obra NO recibió se queda en el camión: la faltante es stock, no una nota', function () use ($stockEn): void {
    $requisicion = ($this->pedido)();
    $linea = $requisicion->lineas()->firstOrFail();

    $this->service->despachar($requisicion, Ubicacion::bodega($this->bodega->id), vehiculo: $this->volqueta);
    $this->service->marcarEnTransito($requisicion->refresh());

    // Bajaron 35 de las 40 que subieron.
    $this->service->recibir($requisicion->refresh(), [$linea->id => '35']);

    expect($stockEn('proyecto_id', $this->obra->id, $this->cemento->id))->toBe('35.0000')
        // Las 5 que faltan siguen arriba del camión, donde alguien las
        // tiene que devolver a bodega o dar por perdidas.
        ->and($stockEn('bodega_id', $this->volqueta->id, $this->cemento->id))->toBe('5.0000');

    expect($this->service->conciliar($requisicion->refresh()))
        ->toBe(EstadoRequisicion::Discrepancia);
});

test('sin camión declarado el despacho se comporta exactamente como siempre', function () use ($stockEn): void {
    $requisicion = ($this->pedido)();

    $this->service->despachar($requisicion, Ubicacion::bodega($this->bodega->id));

    // El camino viejo: la obra recibe el stock al despachar.
    expect($stockEn('proyecto_id', $this->obra->id, $this->cemento->id))->toBe('40.0000')
        ->and($requisicion->fresh()->vehiculo_id)->toBeNull();
});

test('el viaje ocupa la volqueta en el calendario y la libera al recibir', function (): void {
    $requisicion = ($this->pedido)();

    $this->service->despachar($requisicion, Ubicacion::bodega($this->bodega->id), vehiculo: $this->volqueta);

    // Mientras reparte, nadie debería poder agendarla a otra obra.
    expect($requisicion->fresh()->asignacion_viaje_id)->not->toBeNull();

    $this->service->marcarEnTransito($requisicion->refresh());
    $this->service->recibir($requisicion->refresh());

    expect($requisicion->fresh()->asignacion_viaje_id)->toBeNull();
});
