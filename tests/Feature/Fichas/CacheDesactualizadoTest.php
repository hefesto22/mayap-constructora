<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Fichas\CalcularPrecioFichaService;

beforeEach(function (): void {
    $this->service = new CalcularPrecioFichaService;
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->unidadM2 = UnidadMedida::factory()->create(['codigo' => 'M2']);
    $this->unidadJDR = UnidadMedida::factory()->create(['codigo' => 'JDR']);
});

test('ficha sin recalcular nunca aparece como cache desactualizado', function (): void {
    Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->create();

    expect(Ficha::cacheDesactualizado()->count())->toBe(1);
});

test('ficha recalculada con precios estables NO está desactualizada', function (): void {
    $ficha = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->create();

    $albanil = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(800.00)
        ->create();

    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($albanil)
        ->conRendimiento('1.000000', '0.00')
        ->create();

    // Avanzo el tiempo después de crear el item, para que su
    // precio_actualizado_at sea anterior al recálculo de la ficha.
    $this->travel(2)->seconds();

    $this->service->recalcularYPersistir($ficha);

    expect(Ficha::cacheDesactualizado()->count())->toBe(0);
});

test('ficha queda desactualizada cuando un item referenciado cambia de precio', function (): void {
    $ficha = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->create();

    $albanil = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(800.00)
        ->create();

    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($albanil)
        ->conRendimiento('1.000000', '0.00')
        ->create();

    $this->travel(2)->seconds();
    $this->service->recalcularYPersistir($ficha);

    expect(Ficha::cacheDesactualizado()->count())->toBe(0);

    // Avanza el tiempo y cambia el precio del item.
    // ItemObserver actualiza precio_actualizado_at automáticamente.
    $this->travel(5)->seconds();
    $albanil->update(['precio_unitario' => 900.00]);

    expect(Ficha::cacheDesactualizado()->count())->toBe(1);
});

test('ficha que cambia un item NO referenciado por ella queda intacta', function (): void {
    $fichaA = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->create();
    $fichaB = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->create();

    $itemA = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(500.00)
        ->create();

    $itemB = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(600.00)
        ->create();

    FichaLinea::factory()->paraFicha($fichaA)->conItem($itemA)
        ->conRendimiento('1.000000', '0.00')->create();
    FichaLinea::factory()->paraFicha($fichaB)->conItem($itemB)
        ->conRendimiento('1.000000', '0.00')->create();

    $this->travel(2)->seconds();
    $this->service->recalcularYPersistir($fichaA);
    $this->service->recalcularYPersistir($fichaB);

    expect(Ficha::cacheDesactualizado()->count())->toBe(0);

    // Cambio precio de itemA. Solo fichaA debería quedar desactualizada.
    $this->travel(5)->seconds();
    $itemA->update(['precio_unitario' => 700.00]);

    $desactualizadas = Ficha::cacheDesactualizado()->pluck('id')->all();

    expect($desactualizadas)->toContain($fichaA->id);
    expect($desactualizadas)->not->toContain($fichaB->id);
});
