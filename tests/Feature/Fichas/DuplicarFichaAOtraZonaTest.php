<?php

declare(strict_types=1);

use App\Enums\CategoriaBaseLinea;
use App\Enums\CategoriaItem;
use App\Enums\TipoLineaFicha;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Fichas\DuplicarFichaAOtraZona;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->service = new DuplicarFichaAOtraZona;
    $this->src = Zona::factory()->create(['codigo' => 'SRC', 'nombre' => 'Santa Rosa']);
    $this->tgu = Zona::factory()->create(['codigo' => 'TGU', 'nombre' => 'Tegucigalpa']);
    $this->unidadM2 = UnidadMedida::factory()->create(['codigo' => 'M2']);
    $this->unidadJDR = UnidadMedida::factory()->create(['codigo' => 'JDR']);
});

test('duplica una ficha con líneas tipo item creando los faltantes en zona destino', function (): void {
    // Items en zona origen
    $cementoSrc = Item::factory()
        ->enZona($this->src)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::Materiales)
        ->conPrecio(220.00)
        ->create(['nombre' => 'CEMENTO']);

    $albanilSrc = Item::factory()
        ->enZona($this->src)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(750.00)
        ->create(['nombre' => 'ALBAÑIL']);

    // En zona destino: solo existe el albañil (con precio distinto)
    Item::factory()
        ->enZona($this->tgu)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(800.00)
        ->create(['nombre' => 'ALBAÑIL']);

    $fichaOrigen = Ficha::factory()
        ->enZona($this->src)
        ->conUnidad($this->unidadM2)
        ->create(['nombre' => 'LOSA DEMO']);

    FichaLinea::factory()->paraFicha($fichaOrigen)
        ->conItem($cementoSrc)->conRendimiento('0.85', '5.00')->create(['orden' => 0]);
    FichaLinea::factory()->paraFicha($fichaOrigen)
        ->conItem($albanilSrc)->conRendimiento('0.5', '0.00')->create(['orden' => 1]);

    $resultado = $this->service->ejecutar($fichaOrigen, $this->tgu);

    expect($resultado['items_reutilizados'])->toBe(1); // ALBAÑIL ya existía
    expect($resultado['items_creados'])->toBe(1);     // CEMENTO se creó
    expect($resultado['ids_items_creados'])->toHaveCount(1);

    // La ficha destino existe en zona TGU con código auto-generado
    $fichaDestino = $resultado['ficha_destino'];
    expect($fichaDestino->zona_id)->toBe($this->tgu->id);
    expect($fichaDestino->codigo)->toStartWith('TGU-APU-');
    expect($fichaDestino->lineas()->count())->toBe(2);

    // El item creado en zona destino tiene precio 0 con observación
    $cementoTgu = Item::where('zona_id', $this->tgu->id)->where('nombre', 'CEMENTO')->first();
    expect($cementoTgu)->not->toBeNull();
    expect((float) $cementoTgu->precio_unitario)->toBe(0.0);
    expect($cementoTgu->observaciones_precio)->toContain('REVISAR');
});

test('duplica las líneas tipo porcentaje sin transformación', function (): void {
    $albanilSrc = Item::factory()
        ->enZona($this->src)
        ->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)
        ->conPrecio(750.00)
        ->create(['nombre' => 'ALBAÑIL']);

    $fichaOrigen = Ficha::factory()->enZona($this->src)->conUnidad($this->unidadM2)->create();

    FichaLinea::factory()->paraFicha($fichaOrigen)
        ->conItem($albanilSrc)->conRendimiento('1', '0')->create(['orden' => 0]);

    FichaLinea::factory()->paraFicha($fichaOrigen)
        ->porcentaje('HERRAMIENTA MENOR', 5.00, CategoriaBaseLinea::ManoObra, CategoriaItem::HerramientaEquipo)
        ->create(['orden' => 1]);

    $resultado = $this->service->ejecutar($fichaOrigen, $this->tgu);
    $fichaDestino = $resultado['ficha_destino'];

    $lineasDestino = $fichaDestino->lineas()->orderBy('orden')->get();
    expect($lineasDestino)->toHaveCount(2);

    $linea2 = $lineasDestino->last();
    expect($linea2->tipo)->toBe(TipoLineaFicha::Porcentaje);
    expect($linea2->descripcion)->toBe('HERRAMIENTA MENOR');
    expect((float) $linea2->porcentaje)->toBe(5.00);
    expect($linea2->categoria_base)->toBe(CategoriaBaseLinea::ManoObra);
    expect($linea2->categoria_destino)->toBe(CategoriaItem::HerramientaEquipo);
});

test('la ficha destino es independiente: editar origen no afecta destino', function (): void {
    $item = Item::factory()->enZona($this->src)->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)->conPrecio(500.00)
        ->create(['nombre' => 'ALBAÑIL']);

    $fichaOrigen = Ficha::factory()->enZona($this->src)->conUnidad($this->unidadM2)->create();
    FichaLinea::factory()->paraFicha($fichaOrigen)->conItem($item)
        ->conRendimiento('1', '0')->create();

    $resultado = $this->service->ejecutar($fichaOrigen, $this->tgu);

    // Modificar la ficha origen — el destino NO debe cambiar
    $fichaOrigen->update(['nombre' => 'CAMBIO EN ORIGEN']);

    $fichaDestino = $resultado['ficha_destino']->fresh();
    expect($fichaDestino->nombre)->not->toBe('CAMBIO EN ORIGEN');
});

test('registra entrada en activitylog con properties correctos', function (): void {
    $item = Item::factory()->enZona($this->src)->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)->conPrecio(500.00)
        ->create(['nombre' => 'ALBAÑIL']);

    $ficha = Ficha::factory()->enZona($this->src)->conUnidad($this->unidadM2)->create();
    FichaLinea::factory()->paraFicha($ficha)->conItem($item)->conRendimiento('1', '0')->create();

    $this->service->ejecutar($ficha, $this->tgu);

    $actividad = Activity::query()
        ->where('log_name', 'duplicado_ficha')
        ->latest()
        ->first();

    expect($actividad)->not->toBeNull();
    expect($actividad->properties->get('origen_codigo'))->toBe($ficha->codigo);
    expect($actividad->properties->get('destino_zona'))->toBe('TGU');
    expect($actividad->properties->get('items_creados'))->toBe(1);
});

test('recalcula el cache de precio en la ficha destino automáticamente', function (): void {
    $item = Item::factory()->enZona($this->src)->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)->conPrecio(500.00)
        ->create(['nombre' => 'ALBAÑIL']);

    // En destino el item ya existe con OTRO precio
    Item::factory()->enZona($this->tgu)->conUnidad($this->unidadJDR)
        ->deCategoria(CategoriaItem::ManoObra)->conPrecio(800.00)
        ->create(['nombre' => 'ALBAÑIL']);

    $ficha = Ficha::factory()->enZona($this->src)->conUnidad($this->unidadM2)->conUtilidad(25.00)->create();
    FichaLinea::factory()->paraFicha($ficha)->conItem($item)
        ->conRendimiento('1', '0')->create();

    $resultado = $this->service->ejecutar($ficha, $this->tgu);
    $fichaDestino = $resultado['ficha_destino']->fresh();

    // En zona destino el albañil cuesta 800 → subtotal 800 → precio 1000
    expect((float) $fichaDestino->subtotal_cache)->toBe(800.00);
    expect((float) $fichaDestino->precio_venta_cache)->toBe(1000.00);
    expect($fichaDestino->precio_calculado_at)->not->toBeNull();
});
