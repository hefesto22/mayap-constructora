<?php

declare(strict_types=1);

use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Material;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;

/*
|--------------------------------------------------------------------------
| Costo promedio ponderado INDEPENDIENTE por bodega (ADR-0003).
|--------------------------------------------------------------------------
| Caso del dueño: el mismo material (cemento) puede costar 200 en una bodega
| y 210 en otra. El material es uno solo; el costo se pondera por (material,
| bodega) en su existencia. Las compras alimentan ese promedio.
*/

beforeEach(function (): void {
    $this->service = new RegistrarMovimientoService;
    $this->material = Material::factory()->create(['nombre' => 'CEMENTO GRIS 42.5KG']);
    $this->bodegaA = Bodega::factory()->create(['nombre' => 'BODEGA SANTA ROSA']);
    $this->bodegaB = Bodega::factory()->create(['nombre' => 'BODEGA TEGUCIGALPA']);
});

function existenciaMaterialBodega(int $materialId, int $bodegaId): Existencia
{
    return Existencia::query()
        ->where('material_id', $materialId)
        ->where('bodega_id', $bodegaId)
        ->firstOrFail();
}

test('un mismo material tiene costo promedio independiente en cada bodega', function (): void {
    $this->service->entradaCompra($this->material->id, Ubicacion::bodega($this->bodegaA->id), '100', '200');
    $this->service->entradaCompra($this->material->id, Ubicacion::bodega($this->bodegaB->id), '100', '210');

    $enA = existenciaMaterialBodega($this->material->id, $this->bodegaA->id);
    $enB = existenciaMaterialBodega($this->material->id, $this->bodegaB->id);

    expect($enA->costo_promedio)->toBe('200.00')
        ->and($enB->costo_promedio)->toBe('210.00')
        ->and(Existencia::where('material_id', $this->material->id)->count())->toBe(2);
});

test('comprar el mismo material a la misma bodega pondera el costo, no crea otra fila', function (): void {
    $bodega = Ubicacion::bodega($this->bodegaA->id);

    $this->service->entradaCompra($this->material->id, $bodega, '100', '200');
    $this->service->entradaCompra($this->material->id, $bodega, '100', '210');

    $existencia = existenciaMaterialBodega($this->material->id, $this->bodegaA->id);

    expect($existencia->cantidad)->toBe('200.0000')
        ->and($existencia->costo_promedio)->toBe('205.00')
        ->and(Existencia::where('material_id', $this->material->id)->count())->toBe(1);
});
