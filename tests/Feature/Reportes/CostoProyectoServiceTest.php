<?php

declare(strict_types=1);

use App\Models\Bodega;
use App\Models\Item;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\RegistrarConsumoCombustibleService;
use App\Services\Maquinaria\RegistrarParteService;
use App\Services\Reportes\CostoProyectoService;

/*
|--------------------------------------------------------------------------
| Golden tests del costo real por obra (materiales + maquinaria vs presupuesto).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = new CostoProyectoService;
    $this->inventario = new RegistrarMovimientoService;
    $this->asignar = new AsignarMaquinaService;
    $this->partes = new RegistrarParteService;
    $this->combustible = new RegistrarConsumoCombustibleService;

    $this->obra = Proyecto::factory()->create(['subtotal_cache' => 100000]);
    $this->bodega = Bodega::factory()->create();
    $this->item = Item::factory()->create();
});

test('una obra sin costos tiene margen igual al presupuesto', function (): void {
    $costo = $this->service->calcular($this->obra);

    expect($costo->costoTotal)->toBe('0.00')
        ->and($costo->margen)->toBe('100000.00')
        ->and($costo->margenPorcentaje)->toBe('100.00');
});

test('el costo de materiales es lo despachado a la obra menos lo devuelto', function (): void {
    // Entra stock a bodega: 100 u a L.50 = 5,000.
    $this->inventario->entradaCompra(
        itemId: $this->item->id,
        destino: Ubicacion::bodega($this->bodega->id),
        cantidad: '100',
        costoUnitario: '50',
    );

    // Despacha 60 u a la obra → 60 × 50 = 3,000.
    $this->inventario->salidaDespacho(
        itemId: $this->item->id,
        origen: Ubicacion::bodega($this->bodega->id),
        destino: Ubicacion::obra($this->obra->id),
        cantidad: '60',
    );

    // Devuelve 10 u a bodega → 10 × 50 = 500 de regreso.
    $this->inventario->devolucion(
        itemId: $this->item->id,
        origen: Ubicacion::obra($this->obra->id),
        destino: Ubicacion::bodega($this->bodega->id),
        cantidad: '10',
    );

    $costo = $this->service->calcular($this->obra);

    // 3,000 despachado − 500 devuelto = 2,500.
    expect($costo->costoMateriales)->toBe('2500.00');
});

test('el costo de maquinaria suma partes de trabajo y combustible', function (): void {
    $maquina = Maquina::factory()->create(['jornada_horas' => 8, 'horometro_actual' => 0]);
    $asignacion = $this->asignar->asignar($maquina, $this->obra->id, tarifaPactada: '1000');

    // Parte: 8 h × 1,000 = 8,000.
    $this->partes->registrarManual($asignacion, horas: '8');

    // Combustible: 50 L × 100 = 5,000.
    $this->combustible->registrar($asignacion, litros: '50', precioLitro: '100');

    $costo = $this->service->calcular($this->obra);

    expect($costo->costoMaquinaria)->toBe('13000.00');
});

test('GOLDEN: el costo total junta materiales y maquinaria y calcula el margen', function (): void {
    // Materiales: despacha 40 u a L.25 = 1,000.
    $this->inventario->entradaCompra(
        itemId: $this->item->id,
        destino: Ubicacion::bodega($this->bodega->id),
        cantidad: '40',
        costoUnitario: '25',
    );
    $this->inventario->salidaDespacho(
        itemId: $this->item->id,
        origen: Ubicacion::bodega($this->bodega->id),
        destino: Ubicacion::obra($this->obra->id),
        cantidad: '40',
    );

    // Maquinaria: parte 5 h × 2,000 = 10,000.
    $maquina = Maquina::factory()->create(['jornada_horas' => 8, 'horometro_actual' => 0]);
    $asignacion = $this->asignar->asignar($maquina, $this->obra->id, tarifaPactada: '2000');
    $this->partes->registrarManual($asignacion, horas: '5');

    $costo = $this->service->calcular($this->obra);

    // 1,000 + 10,000 + 0 = 11,000. Presupuesto 100,000 → margen 89,000 (89%).
    expect($costo->costoMateriales)->toBe('1000.00')
        ->and($costo->costoMaquinaria)->toBe('10000.00')
        ->and($costo->costoManoObra)->toBe('0.00')
        ->and($costo->costoTotal)->toBe('11000.00')
        ->and($costo->margen)->toBe('89000.00')
        ->and($costo->margenPorcentaje)->toBe('89.00');
});
