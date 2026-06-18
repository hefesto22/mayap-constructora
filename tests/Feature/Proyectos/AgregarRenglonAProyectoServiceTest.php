<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Exceptions\Proyectos\ProyectoNoEditableException;
use App\Exceptions\Proyectos\ZonaIncompatibleException;
use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Proyectos\AgregarRenglonAProyectoService;

beforeEach(function (): void {
    $this->service = new AgregarRenglonAProyectoService;
    $this->src = Zona::factory()->create(['codigo' => 'SRC']);
    $this->tgu = Zona::factory()->create(['codigo' => 'TGU']);
    $this->cliente = Cliente::factory()->create();
    $this->unidad = UnidadMedida::factory()->create();

    $this->fichaSrc = Ficha::factory()
        ->enZona($this->src)
        ->conUnidad($this->unidad)
        ->create(['precio_venta_cache' => '2604.37']);

    $this->fichaTgu = Ficha::factory()
        ->enZona($this->tgu)
        ->conUnidad($this->unidad)
        ->create(['precio_venta_cache' => '2750.00']);

    $this->proyecto = Proyecto::factory()
        ->enZona($this->src)
        ->paraCliente($this->cliente)
        ->create();
});

test('agrega renglón con snapshot del precio actual de la ficha', function (): void {
    $renglon = $this->service->ejecutar(
        $this->proyecto,
        $this->fichaSrc,
        cantidad: '120.5000',
        capitulo: '03 ESTRUCTURA',
        notas: 'LOSA DE LA SALA',
    );

    expect($renglon->ficha_id)->toBe($this->fichaSrc->id);
    expect($renglon->cantidad)->toBe('120.5000');
    expect($renglon->precio_unitario_snapshot)->toBe('2604.37');
    // 120.5 × 2604.37 = 313826.585 → 313826.59
    expect($renglon->subtotal_cache)->toBe('313826.59');
    expect($renglon->capitulo)->toBe('03 ESTRUCTURA');
    expect($renglon->notas)->toBe('LOSA DE LA SALA');
});

test('asigna orden secuencial automáticamente al agregar', function (): void {
    $r1 = $this->service->ejecutar($this->proyecto, $this->fichaSrc, '10');
    $r2 = $this->service->ejecutar($this->proyecto, $this->fichaSrc, '20');
    $r3 = $this->service->ejecutar($this->proyecto, $this->fichaSrc, '30');

    expect($r1->orden)->toBe(1);
    expect($r2->orden)->toBe(2);
    expect($r3->orden)->toBe(3);
});

test('recalcula totales del proyecto después de agregar', function (): void {
    expect($this->proyecto->total_cache)->toBe('0.00');

    $this->service->ejecutar($this->proyecto, $this->fichaSrc, '10');

    $this->proyecto->refresh();
    // 10 × 2604.37 = 26043.70; ISV 15% = 3906.555; total = 29950.255
    expect($this->proyecto->subtotal_cache)->toBe('26043.70');
    expect((float) $this->proyecto->total_cache)->toBeGreaterThan(29950.00);
    expect((float) $this->proyecto->total_cache)->toBeLessThan(29951.00);
});

test('rechaza ficha de zona distinta con ZonaIncompatibleException', function (): void {
    expect(fn () => $this->service->ejecutar(
        $this->proyecto,
        $this->fichaTgu,  // ficha de TGU, proyecto de SRC
        cantidad: '5',
    ))->toThrow(ZonaIncompatibleException::class);
});

test('ZonaIncompatibleException expone IDs y códigos de zona', function (): void {
    try {
        $this->service->ejecutar($this->proyecto, $this->fichaTgu, '5');
        expect(false)->toBeTrue('Debió lanzar excepción');
    } catch (ZonaIncompatibleException $e) {
        expect($e->proyectoId)->toBe($this->proyecto->id);
        expect($e->proyectoZonaCodigo)->toBe('SRC');
        expect($e->fichaId)->toBe($this->fichaTgu->id);
        expect($e->fichaZonaCodigo)->toBe('TGU');
    }
});

test('rechaza proyecto en estado Enviada con ProyectoNoEditableException', function (): void {
    $proyectoEnviado = Proyecto::factory()
        ->enZona($this->src)
        ->paraCliente($this->cliente)
        ->enviada()
        ->create();

    expect(fn () => $this->service->ejecutar(
        $proyectoEnviado,
        $this->fichaSrc,
        cantidad: '5',
    ))->toThrow(ProyectoNoEditableException::class);
});

test('rechaza proyecto Aprobado, Rechazado, Vencido', function (): void {
    foreach ([EstadoProyecto::Aprobada, EstadoProyecto::Rechazada, EstadoProyecto::Vencida] as $estado) {
        $proy = Proyecto::factory()
            ->enZona($this->src)
            ->paraCliente($this->cliente)
            ->conEstado($estado)
            ->create();

        expect(fn () => $this->service->ejecutar($proy, $this->fichaSrc, '1'))
            ->toThrow(ProyectoNoEditableException::class);
    }
});

test('snapshot NO cambia cuando la ficha actualiza su precio después', function (): void {
    $renglon = $this->service->ejecutar($this->proyecto, $this->fichaSrc, '10');

    expect($renglon->precio_unitario_snapshot)->toBe('2604.37');

    // El precio de la ficha cambia
    $this->fichaSrc->update(['precio_venta_cache' => '3000.00']);

    // El renglón mantiene el snapshot original
    $renglon->refresh();
    expect($renglon->precio_unitario_snapshot)->toBe('2604.37');
    expect($renglon->subtotal_cache)->toBe('26043.70');
});
