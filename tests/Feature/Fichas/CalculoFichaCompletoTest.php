<?php

declare(strict_types=1);

use App\Enums\CategoriaBaseLinea;
use App\Enums\CategoriaItem;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Fichas\CalcularPrecioFichaService;
use Database\Seeders\FichaDemoSeeder;

beforeEach(function (): void {
    $this->service = new CalcularPrecioFichaService;
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->unidadM2 = UnidadMedida::factory()->create(['codigo' => 'M2']);
    $this->unidadJDR = UnidadMedida::factory()->create(['codigo' => 'JDR']);
    $this->unidadBOLSA = UnidadMedida::factory()->create(['codigo' => 'BOLSA']);
});

test('ficha vacía da precio venta = 0', function (): void {
    $ficha = Ficha::factory()->enZona($this->zona)->conUnidad($this->unidadM2)->create();

    $resultado = $this->service->calcular($ficha);

    expect($resultado->subtotal)->toBe('0.00');
    expect($resultado->utilidadMonto)->toBe('0.00');
    expect($resultado->precioVenta)->toBe('0.00');
});

test('ficha con un solo item sin desperdicio: aplica utilidad correctamente', function (): void {
    $ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadM2)
        ->conUtilidad(25.00)
        ->create();

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

    $resultado = $this->service->calcular($ficha);

    // 1.0 * 800 = 800; utilidad 25% = 200; precio = 1000
    expect($resultado->subtotalDe(CategoriaItem::ManoObra))->toBe('800.00');
    expect($resultado->subtotal)->toBe('800.00');
    expect($resultado->utilidadMonto)->toBe('200.00');
    expect($resultado->precioVenta)->toBe('1000.00');
});

test('ficha con item + desperdicio: el rendimiento efectivo determina el subtotal', function (): void {
    // El rendimiento se captura como EFECTIVO (con la pérdida ya considerada).
    // El desperdicio queda como metadato informativo.
    $ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadM2)
        ->conUtilidad(25.00)
        ->create();

    $cemento = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadBOLSA)
        ->deCategoria(CategoriaItem::Materiales)
        ->conPrecio(220.00)
        ->create();

    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($cemento)
        ->conRendimiento('0.892500', '5.00')  // efectivo (= 0.85 teórico × 1.05)
        ->create();

    $resultado = $this->service->calcular($ficha);

    // 0.892500 × 220 = 196.35
    expect($resultado->subtotalDe(CategoriaItem::Materiales))->toBe('196.35');
    expect($resultado->precioVenta)->toBe('245.44'); // 196.35 + 49.0875 = 245.4375 → 245.44
});

test('ficha con línea % sobre Mano de Obra (HERRAMIENTA MENOR del oficio)', function (): void {
    $ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadM2)
        ->conUtilidad(25.00)
        ->create();

    $albanil = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(750.00)
        ->create();

    // Línea de MO: rendimiento 1.3 con 0% desperdicio = 975 exacto
    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($albanil)
        ->conRendimiento('1.300000', '0.00')
        ->create(['orden' => 0]);

    // Línea % HERRAMIENTA MENOR 5% sobre MO, va en sección HE
    FichaLinea::factory()
        ->paraFicha($ficha)
        ->porcentaje(
            'HERRAMIENTA MENOR',
            5.00,
            CategoriaBaseLinea::ManoObra,
            CategoriaItem::HerramientaEquipo
        )
        ->create(['orden' => 1]);

    $resultado = $this->service->calcular($ficha);

    expect($resultado->subtotalDe(CategoriaItem::ManoObra))->toBe('975.00');
    // 5% × 975 = 48.75, va en sección HE
    expect($resultado->subtotalDe(CategoriaItem::HerramientaEquipo))->toBe('48.75');
    expect($resultado->subtotal)->toBe('1023.75');
});

test('línea % sobre costo_directo se calcula DESPUÉS de las puntuales', function (): void {
    $ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadM2)
        ->conUtilidad(25.00)
        ->create();

    // 100 en materiales
    $material = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadBOLSA)
        ->deCategoria(CategoriaItem::Materiales)
        ->conPrecio(100.00)
        ->create();

    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($material)
        ->conRendimiento('1.000000', '0.00')
        ->create(['orden' => 0]);

    // 200 en mano de obra
    $albanil = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(200.00)
        ->create();

    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($albanil)
        ->conRendimiento('1.000000', '0.00')
        ->create(['orden' => 1]);

    // % HERRAMIENTA MENOR 10% sobre MO → 20, va en HE
    FichaLinea::factory()
        ->paraFicha($ficha)
        ->porcentaje(
            'HERRAMIENTA MENOR',
            10.00,
            CategoriaBaseLinea::ManoObra,
            CategoriaItem::HerramientaEquipo
        )
        ->create(['orden' => 2]);

    // % IMPREVISTOS 5% sobre costo_directo, va en Indirectos
    // En este punto cd = 100 + 200 + 20 = 320 → 5% = 16
    FichaLinea::factory()
        ->paraFicha($ficha)
        ->porcentaje(
            'IMPREVISTOS',
            5.00,
            CategoriaBaseLinea::CostoDirecto,
            CategoriaItem::Indirectos
        )
        ->create(['orden' => 3]);

    $resultado = $this->service->calcular($ficha);

    expect($resultado->costoDirecto)->toBe('320.00');
    expect($resultado->subtotalDe(CategoriaItem::Indirectos))->toBe('16.00');
    expect($resultado->subtotal)->toBe('336.00'); // 320 + 16
    expect($resultado->utilidadMonto)->toBe('84.00'); // 25% × 336
    expect($resultado->precioVenta)->toBe('420.00');
});

test('indirectos forman parte del subtotal sobre el que se aplica utilidad', function (): void {
    $ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadM2)
        ->conUtilidad(20.00)
        ->create();

    // 1000 en MO
    $albanil = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(1000.00)
        ->create();

    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($albanil)
        ->conRendimiento('1.000000', '0.00')
        ->create();

    // 200 en indirectos (item directo, no %)
    $supervision = Item::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::Indirectos)
        ->conPrecio(200.00)
        ->create();

    FichaLinea::factory()
        ->paraFicha($ficha)
        ->conItem($supervision)
        ->conRendimiento('1.000000', '0.00')
        ->create();

    $resultado = $this->service->calcular($ficha);

    expect($resultado->costoDirecto)->toBe('1000.00');
    expect($resultado->subtotalDe(CategoriaItem::Indirectos))->toBe('200.00');
    expect($resultado->subtotal)->toBe('1200.00');
    expect($resultado->utilidadMonto)->toBe('240.00'); // 20% × 1200, no solo × 1000
    expect($resultado->precioVenta)->toBe('1440.00');
});

test('recalcularYPersistir actualiza el cache y el timestamp', function (): void {
    $ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidadM2)
        ->conUtilidad(25.00)
        ->create();

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

    expect($ficha->fresh()->precio_calculado_at)->toBeNull();

    $this->service->recalcularYPersistir($ficha);

    $fresh = $ficha->fresh();

    expect($fresh->subtotal_cache)->toBe('800.00');
    expect($fresh->precio_venta_cache)->toBe('1000.00');
    expect($fresh->precio_calculado_at)->not->toBeNull();
});

// ─── GOLDEN TEST: la ficha real del cliente ────────────────────────

test('GOLDEN: ficha real de losa de concreto reproduce L 2,604.37 al céntimo', function (): void {
    $this->seed(FichaDemoSeeder::class);

    $ficha = Ficha::where('zona_id', $this->zona->id)
        ->where('nombre', 'LIKE', 'LOSA DE CONCRETO ALIGERADA%')
        ->first();

    expect($ficha)->not->toBeNull();

    $resultado = $this->service->calcular($ficha);

    // Verificación al céntimo del Excel del cliente.
    expect($resultado->subtotal)->toBe('2083.50');
    expect($resultado->utilidadPorcentaje)->toBe('25.00');
    expect($resultado->utilidadMonto)->toBe('520.87');
    expect($resultado->precioVenta)->toBe('2604.37');

    // 17 líneas: 16 items + 1 % HERRAMIENTA MENOR
    expect($ficha->lineas)->toHaveCount(17);
});

test('GOLDEN: subtotales por categoría de la ficha real del cliente', function (): void {
    $this->seed(FichaDemoSeeder::class);

    $ficha = Ficha::where('zona_id', $this->zona->id)
        ->where('nombre', 'LIKE', 'LOSA DE CONCRETO ALIGERADA%')
        ->first();

    $resultado = $this->service->calcular($ficha);

    // Materiales: cemento + arena + grava + agua + lámina + canaleta
    //           + var#4 + alambre + tornillos + clavos
    expect($resultado->subtotalDe(CategoriaItem::Materiales))->toBe('1036.46');

    // MO: 3 jornadas × precios distintos = 375 + 375 + 225
    expect($resultado->subtotalDe(CategoriaItem::ManoObra))->toBe('975.00');

    // HE: concretera + vibrador + soldadora + 5% herramienta menor sobre MO
    expect($resultado->subtotalDe(CategoriaItem::HerramientaEquipo))->toBe('72.04');

    // Esta ficha NO tiene indirectos
    expect($resultado->subtotalDe(CategoriaItem::Indirectos))->toBe('0.00');
});
