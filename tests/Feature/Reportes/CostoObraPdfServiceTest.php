<?php

declare(strict_types=1);

use App\Models\Bodega;
use App\Models\Material;
use App\Models\Proyecto;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Reportes\CostoObraPdfService;

/*
|--------------------------------------------------------------------------
| Test del armado del HTML del reporte de costo (sin invocar Chromium).
|--------------------------------------------------------------------------
*/

test('el HTML del reporte incluye la obra y su desglose de costo', function (): void {
    $obra = Proyecto::factory()->create([
        'nombre'         => 'PAVIMENTACIÓN CALLE 5',
        'subtotal_cache' => 100000,
    ]);

    // Materiales: despacha 40 u a L.25 = 1,000 a la obra.
    $bodega = Bodega::factory()->create();
    $material = Material::factory()->create();
    $inventario = new RegistrarMovimientoService;
    $inventario->entradaCompra(
        materialId: $material->id,
        destino: Ubicacion::bodega($bodega->id),
        cantidad: '40',
        costoUnitario: '25',
    );
    $inventario->salidaDespacho(
        materialId: $material->id,
        origen: Ubicacion::bodega($bodega->id),
        destino: Ubicacion::obra($obra->id),
        cantidad: '40',
    );

    // Resolver por el container (el constructor también inyecta PdfRenderer;
    // instanciar con `new` se rompe cada vez que crecen las dependencias).
    $html = app(CostoObraPdfService::class)->construirHtml($obra);

    expect($html)
        ->toContain('CONSTRUCTORA MAYAP')
        ->toContain('Estado de costo de obra')
        ->toContain($obra->codigo)
        ->toContain('PAVIMENTACIÓN CALLE 5')
        ->toContain('Materiales')
        ->toContain('Mano de obra')
        // Costo de materiales 1,000 y presupuesto 100,000 formateados.
        ->toContain('1,000.00')
        ->toContain('100,000.00');
});
