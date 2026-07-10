<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Fichas\RecalcularFichasDeZona;

/*
|--------------------------------------------------------------------------
| Recalcular todas las fichas de una zona en un solo paso. Cubre el caso:
| "actualicé varios precios y quiero propagar a todas las fichas de la zona".
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->src = Zona::factory()->create(['codigo' => 'SRC', 'nombre' => 'Santa Rosa']);
    $this->tgu = Zona::factory()->create(['codigo' => 'TGU', 'nombre' => 'Tegucigalpa']);
    $this->unidadM2 = UnidadMedida::factory()->create(['codigo' => 'M2']);
    $this->unidadJDR = UnidadMedida::factory()->create(['codigo' => 'JDR']);
});

test('recalcula todas las fichas activas de la zona y deja el cache al día', function (): void {
    $albanil = Item::factory()->enZona($this->src)->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)->create(['precio_unitario' => 800]);

    $fichas = Ficha::factory()->count(3)->enZona($this->src)->conUnidad($this->unidadM2)
        ->conUtilidad(25.00)->create();

    foreach ($fichas as $f) {
        FichaLinea::factory()->paraFicha($f)->conItem($albanil)->conRendimiento('1.000000', '0.00')->create();
    }

    // Antes: ninguna tiene cache calculado.
    foreach ($fichas as $f) {
        expect($f->precio_calculado_at)->toBeNull();
    }

    $count = app(RecalcularFichasDeZona::class)->ejecutar($this->src);

    expect($count)->toBe(3);

    foreach ($fichas as $f) {
        $f->refresh();
        expect((float) $f->subtotal_cache)->toBe(800.00)
            ->and((float) $f->precio_venta_cache)->toBe(1000.00) // 800 + 25%
            ->and($f->precio_calculado_at)->not->toBeNull();
    }
});

test('no toca las fichas de otras zonas', function (): void {
    $itemSrc = Item::factory()->enZona($this->src)->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)->create(['precio_unitario' => 500]);
    $itemTgu = Item::factory()->enZona($this->tgu)->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)->create(['precio_unitario' => 500]);

    $fichaSrc = Ficha::factory()->enZona($this->src)->conUnidad($this->unidadM2)->conUtilidad(0)->create();
    FichaLinea::factory()->paraFicha($fichaSrc)->conItem($itemSrc)->conRendimiento('1', '0')->create();

    $fichaTgu = Ficha::factory()->enZona($this->tgu)->conUnidad($this->unidadM2)->conUtilidad(0)->create();
    FichaLinea::factory()->paraFicha($fichaTgu)->conItem($itemTgu)->conRendimiento('1', '0')->create();

    app(RecalcularFichasDeZona::class)->ejecutar($this->src);

    $fichaSrc->refresh();
    $fichaTgu->refresh();

    expect($fichaSrc->precio_calculado_at)->not->toBeNull()
        ->and($fichaTgu->precio_calculado_at)->toBeNull();
});

test('ignora las fichas inactivas de la zona', function (): void {
    $item = Item::factory()->enZona($this->src)->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)->create(['precio_unitario' => 700]);

    $activa = Ficha::factory()->enZona($this->src)->conUnidad($this->unidadM2)->conUtilidad(0)->create();
    FichaLinea::factory()->paraFicha($activa)->conItem($item)->conRendimiento('1', '0')->create();

    $inactiva = Ficha::factory()->enZona($this->src)->conUnidad($this->unidadM2)->inactiva()->conUtilidad(0)->create();
    FichaLinea::factory()->paraFicha($inactiva)->conItem($item)->conRendimiento('1', '0')->create();

    $count = app(RecalcularFichasDeZona::class)->ejecutar($this->src);

    expect($count)->toBe(1);

    $inactiva->refresh();
    expect($inactiva->precio_calculado_at)->toBeNull();
});
