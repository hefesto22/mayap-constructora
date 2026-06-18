<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Proyectos\CalcularPrecioProyectoService;

beforeEach(function (): void {
    $this->service = new CalcularPrecioProyectoService;
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->unidad = UnidadMedida::factory()->create();
    $this->ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create();
});

test('proyecto sin renglones tiene total 0', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    $resultado = $this->service->recalcular($proyecto);

    expect($resultado->subtotal_cache)->toBe('0.00');
    expect($resultado->isv_cache)->toBe('0.00');
    expect($resultado->total_cache)->toBe('0.00');
    expect($resultado->precio_calculado_at)->not->toBeNull();
});

test('proyecto con renglones suma subtotales y aplica ISV 15%', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['aplica_isv' => true, 'isv_porcentaje' => 15.00]);

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('10', '1000.00')   // subtotal 10000
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('5', '500.00')     // subtotal 2500
        ->create();

    $resultado = $this->service->recalcular($proyecto);

    expect($resultado->subtotal_cache)->toBe('12500.00');
    expect($resultado->isv_cache)->toBe('1875.00');
    expect($resultado->total_cache)->toBe('14375.00');
});

test('proyecto exento de ISV aplica 0% y total = subtotal', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->exento()
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('20', '500.00')   // subtotal 10000
        ->create();

    $resultado = $this->service->recalcular($proyecto);

    expect($resultado->subtotal_cache)->toBe('10000.00');
    expect($resultado->isv_cache)->toBe('0.00');
    expect($resultado->total_cache)->toBe('10000.00');
});

test('redondeo half-up: 1234.565 × 0.15 → ISV redondea hacia arriba', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    // Subtotal 1234.57 (NO 1234.565 porque ya redondeamos al guardar
    // el subtotal del renglón). 1234.57 × 0.15 = 185.1855 → 185.19
    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('1', '1234.57')
        ->create();

    $resultado = $this->service->recalcular($proyecto);

    expect($resultado->subtotal_cache)->toBe('1234.57');
    expect($resultado->isv_cache)->toBe('185.19');
    expect($resultado->total_cache)->toBe('1419.76');
});

test('previsualizar retorna totales sin persistir', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('3', '1000.00')
        ->create();

    $preview = $this->service->previsualizar($proyecto);

    expect($preview['subtotal'])->toBe('3000.00');
    expect($preview['isv'])->toBe('450.00');
    expect($preview['total'])->toBe('3450.00');

    // Verificar que NO se persistió
    $proyecto->refresh();
    expect($proyecto->subtotal_cache)->toBe('0.00');
    expect($proyecto->precio_calculado_at)->toBeNull();
});

test('recalcular es idempotente: segunda corrida produce mismo resultado', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('7', '432.10')
        ->create();

    $primer = $this->service->recalcular($proyecto);
    $segundo = $this->service->recalcular($proyecto);

    expect($primer->total_cache)->toBe($segundo->total_cache);
    expect($primer->subtotal_cache)->toBe($segundo->subtotal_cache);
});

test('100 renglones con valores variados acumulan sin pérdida de precisión', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    $totalEsperado = '0';

    for ($i = 1; $i <= 100; $i++) {
        $cantidad = (string) ($i * 0.5);
        $precio = (string) ($i * 10.33);
        ProyectoRenglon::factory()
            ->paraProyecto($proyecto)
            ->conFicha($this->ficha)
            ->conCantidad($cantidad, $precio)
            ->create();

        // Sumar lo "esperado" usando bcmath con full precision.
        $sub = bcmul($cantidad, $precio, 4);
        $totalEsperado = bcadd($totalEsperado, bcadd($sub, '0.005', 2), 2);
    }

    $resultado = $this->service->recalcular($proyecto);

    // Tolerancia de centavo por redondeos acumulados (típico en ERPs).
    $diferencia = bcsub($resultado->subtotal_cache, $totalEsperado, 2);
    expect((float) $diferencia)->toBeGreaterThanOrEqual(-0.01);
    expect((float) $diferencia)->toBeLessThanOrEqual(0.01);
});
