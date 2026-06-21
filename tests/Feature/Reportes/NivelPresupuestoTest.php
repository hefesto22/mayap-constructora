<?php

declare(strict_types=1);

use App\Enums\NivelPresupuesto;
use App\Models\Bodega;
use App\Models\Material;
use App\Models\Proyecto;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Reportes\CostoProyectoService;

/*
|--------------------------------------------------------------------------
| Tests del nivel de alerta de presupuesto (umbral 80% / sobregiro).
|--------------------------------------------------------------------------
*/

test('el nivel se clasifica por el porcentaje consumido', function (string $porcentaje, NivelPresupuesto $esperado): void {
    expect(NivelPresupuesto::desdePorcentaje($porcentaje))->toBe($esperado);
})->with([
    ['0.00', NivelPresupuesto::Sano],
    ['79.99', NivelPresupuesto::Sano],
    ['80.00', NivelPresupuesto::EnRiesgo],
    ['95.00', NivelPresupuesto::EnRiesgo],
    ['100.00', NivelPresupuesto::EnRiesgo],
    ['100.01', NivelPresupuesto::Sobregirado],
    ['150.00', NivelPresupuesto::Sobregirado],
]);

test('una obra que pasa el 80% del presupuesto queda en riesgo', function (): void {
    $obra = Proyecto::factory()->create(['subtotal_cache' => 10000]);
    $bodega = Bodega::factory()->create();
    $material = Material::factory()->create();

    $inventario = new RegistrarMovimientoService;
    // Despacha 85 u a L.100 = 8,500 → 85% del presupuesto de 10,000.
    $inventario->entradaCompra(
        materialId: $material->id,
        destino: Ubicacion::bodega($bodega->id),
        cantidad: '85',
        costoUnitario: '100',
    );
    $inventario->salidaDespacho(
        materialId: $material->id,
        origen: Ubicacion::bodega($bodega->id),
        destino: Ubicacion::obra($obra->id),
        cantidad: '85',
    );

    $costo = (new CostoProyectoService)->calcular($obra);

    expect($costo->porcentajeConsumido)->toBe('85.00')
        ->and($costo->nivel())->toBe(NivelPresupuesto::EnRiesgo);
});

test('una obra cuyo costo supera el presupuesto queda sobregirada', function (): void {
    $obra = Proyecto::factory()->create(['subtotal_cache' => 5000]);
    $bodega = Bodega::factory()->create();
    $material = Material::factory()->create();

    $inventario = new RegistrarMovimientoService;
    // Despacha 60 u a L.100 = 6,000 → 120% del presupuesto de 5,000.
    $inventario->entradaCompra(
        materialId: $material->id,
        destino: Ubicacion::bodega($bodega->id),
        cantidad: '60',
        costoUnitario: '100',
    );
    $inventario->salidaDespacho(
        materialId: $material->id,
        origen: Ubicacion::bodega($bodega->id),
        destino: Ubicacion::obra($obra->id),
        cantidad: '60',
    );

    $costo = (new CostoProyectoService)->calcular($obra);

    expect($costo->porcentajeConsumido)->toBe('120.00')
        ->and($costo->nivel())->toBe(NivelPresupuesto::Sobregirado);
});

test('una obra sin presupuesto y sin costo está sana', function (): void {
    $obra = Proyecto::factory()->create(['subtotal_cache' => 0]);

    $costo = (new CostoProyectoService)->calcular($obra);

    expect($costo->porcentajeConsumido)->toBe('0.00')
        ->and($costo->nivel())->toBe(NivelPresupuesto::Sano);
});

test('una obra sin presupuesto pero con costo se considera sobregirada', function (): void {
    $obra = Proyecto::factory()->create(['subtotal_cache' => 0]);
    $bodega = Bodega::factory()->create();
    $material = Material::factory()->create();

    $inventario = new RegistrarMovimientoService;
    $inventario->entradaCompra(
        materialId: $material->id,
        destino: Ubicacion::bodega($bodega->id),
        cantidad: '10',
        costoUnitario: '100',
    );
    $inventario->salidaDespacho(
        materialId: $material->id,
        origen: Ubicacion::bodega($bodega->id),
        destino: Ubicacion::obra($obra->id),
        cantidad: '10',
    );

    expect((new CostoProyectoService)->calcular($obra)->nivel())->toBe(NivelPresupuesto::Sobregirado);
});
