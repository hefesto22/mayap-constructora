<?php

declare(strict_types=1);

use App\Exceptions\Proyectos\ProyectoNoEditableException;
use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Proyectos\ActualizarPreciosProyectoService;
use App\Services\Proyectos\CalcularPrecioProyectoService;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->service = new ActualizarPreciosProyectoService;
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->unidad = UnidadMedida::factory()->create();
    $this->ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create(['precio_venta_cache' => '1000.00']);
});

test('actualiza snapshots al precio actual de las fichas', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('5', '1000.00')   // snapshot 1000
        ->create();

    // El precio de la ficha sube
    $this->ficha->update(['precio_venta_cache' => '1500.00']);

    $resultado = $this->service->ejecutar($proyecto);

    expect($resultado['renglones_actualizados'])->toBe(1);

    $renglonActualizado = $proyecto->fresh()->renglones->first();
    expect($renglonActualizado->precio_unitario_snapshot)->toBe('1500.00');
    expect($renglonActualizado->subtotal_cache)->toBe('7500.00');
});

test('recalcula totales del proyecto al actualizar', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['aplica_isv' => true, 'isv_porcentaje' => 15.00]);

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('10', '1000.00')
        ->create();

    $this->ficha->update(['precio_venta_cache' => '1200.00']);
    $this->service->ejecutar($proyecto);

    $proyecto->refresh();
    expect($proyecto->subtotal_cache)->toBe('12000.00');
    expect($proyecto->isv_cache)->toBe('1800.00');
    expect($proyecto->total_cache)->toBe('13800.00');
});

test('reporta diferencia entre total anterior y nuevo', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->exento()
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('10', '1000.00')
        ->create();

    // Recalcular para que total_cache se inicialice
    (new CalcularPrecioProyectoService)->recalcular($proyecto);
    $proyecto->refresh();
    expect($proyecto->total_cache)->toBe('10000.00');

    $this->ficha->update(['precio_venta_cache' => '1500.00']);
    $resultado = $this->service->ejecutar($proyecto);

    expect($resultado['total_anterior'])->toBe('10000.00');
    expect($resultado['total_nuevo'])->toBe('15000.00');
    expect($resultado['diferencia'])->toBe('5000.00');
});

test('skip si los snapshots ya están al día (no_op)', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('5', '1000.00')   // ya coincide con precio actual
        ->create();

    $resultado = $this->service->ejecutar($proyecto);

    expect($resultado['renglones_actualizados'])->toBe(0);
});

test('rechaza proyecto que NO está en Borrador', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->enviada()
        ->create();

    expect(fn () => $this->service->ejecutar($proyecto))
        ->toThrow(ProyectoNoEditableException::class);
});

test('registra actividad en activitylog con properties', function (): void {
    $proyecto = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($proyecto)
        ->conFicha($this->ficha)
        ->conCantidad('5', '1000.00')
        ->create();

    $this->ficha->update(['precio_venta_cache' => '1100.00']);
    $this->service->ejecutar($proyecto);

    $actividad = Activity::query()
        ->where('log_name', 'actualizacion_precios')
        ->latest()
        ->first();

    expect($actividad)->not->toBeNull();
    expect($actividad->properties->get('codigo'))->toBe($proyecto->codigo);
    expect($actividad->properties->get('renglones_actualizados'))->toBe(1);
});
