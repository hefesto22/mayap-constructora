<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;
use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Proyectos\DuplicarProyectoService;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->service = new DuplicarProyectoService;
    $this->zona = Zona::factory()->create(['codigo' => 'SRC']);
    $this->cliente = Cliente::factory()->create();
    $this->unidad = UnidadMedida::factory()->create();
    $this->ficha = Ficha::factory()
        ->enZona($this->zona)
        ->conUnidad($this->unidad)
        ->create(['precio_venta_cache' => '1000.00']);
});

test('duplicar crea un proyecto independiente con código nuevo', function (): void {
    $origen = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create([
            'nombre'         => 'CASA HABITACION DE 2 NIVELES',
            'direccion_obra' => 'BARRIO CENTRO',
        ]);

    $resultado = $this->service->ejecutar($origen);
    $destino = $resultado['proyecto_destino'];

    expect($destino->id)->not->toBe($origen->id);
    expect($destino->codigo)->not->toBe($origen->codigo);
    expect($destino->nombre)->toBe($origen->nombre);
    expect($destino->cliente_id)->toBe($origen->cliente_id);
    expect($destino->zona_id)->toBe($origen->zona_id);
});

test('duplicado SIEMPRE arranca en estado Borrador, sin importar el origen', function (): void {
    foreach ([EstadoProyecto::Enviada, EstadoProyecto::Aprobada, EstadoProyecto::Rechazada, EstadoProyecto::Vencida] as $estado) {
        $origen = Proyecto::factory()
            ->enZona($this->zona)
            ->paraCliente($this->cliente)
            ->conEstado($estado)
            ->create();

        $resultado = $this->service->ejecutar($origen);

        expect($resultado['proyecto_destino']->estado)->toBe(EstadoProyecto::Borrador);
    }
});

test('duplicado tiene fechas reiniciadas: emisión hoy, validez hoy+30', function (): void {
    $origen = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->conFechasVencidas()  // emisión hace 60 días
        ->create();

    $resultado = $this->service->ejecutar($origen);
    $destino = $resultado['proyecto_destino'];

    expect($destino->fecha_emision->toDateString())->toBe(now()->toDateString());
    expect($destino->fecha_validez->toDateString())->toBe(now()->addDays(30)->toDateString());
});

test('renglones se copian con SNAPSHOTS ACTUALES de las fichas', function (): void {
    $origen = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($origen)
        ->conFicha($this->ficha)
        ->conCantidad('10', '1000.00')   // snapshot viejo
        ->create();

    // Precio de la ficha cambia ANTES de duplicar
    $this->ficha->update(['precio_venta_cache' => '1500.00']);

    $resultado = $this->service->ejecutar($origen);
    $destino = $resultado['proyecto_destino'];

    $renglonDestino = $destino->renglones->first();

    // El destino tiene el snapshot ACTUAL (1500), NO el viejo (1000)
    expect($renglonDestino->precio_unitario_snapshot)->toBe('1500.00');
    expect($renglonDestino->subtotal_cache)->toBe('15000.00');

    // El origen mantiene su snapshot viejo intacto (no afectado)
    $renglonOrigen = $origen->fresh()->renglones->first();
    expect($renglonOrigen->precio_unitario_snapshot)->toBe('1000.00');
});

test('duplicar es independiente: editar origen NO afecta destino', function (): void {
    $origen = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create(['nombre' => 'NOMBRE ORIGINAL']);

    $resultado = $this->service->ejecutar($origen);
    $destino = $resultado['proyecto_destino'];

    $origen->update(['nombre' => 'NOMBRE CAMBIADO']);

    expect($destino->fresh()->nombre)->toBe('NOMBRE ORIGINAL');
});

test('reporta cantidad de renglones copiados y totales', function (): void {
    $origen = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    ProyectoRenglon::factory()
        ->paraProyecto($origen)
        ->conFicha($this->ficha)
        ->conCantidad('1', '1000.00')
        ->count(3)
        ->create();

    $resultado = $this->service->ejecutar($origen);

    expect($resultado['renglones_copiados'])->toBe(3);
    expect((float) $resultado['total_destino'])->toBeGreaterThan(0);
});

test('preserva ISV configurado del origen (incluido proyectos exentos)', function (): void {
    $origen = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->exento()
        ->create();

    $resultado = $this->service->ejecutar($origen);
    $destino = $resultado['proyecto_destino'];

    expect($destino->aplica_isv)->toBeFalse();
    expect($destino->isv_porcentaje)->toBe('0.00');
});

test('registra actividad en activitylog con vínculo origen-destino', function (): void {
    $origen = Proyecto::factory()
        ->enZona($this->zona)
        ->paraCliente($this->cliente)
        ->create();

    $resultado = $this->service->ejecutar($origen);

    $actividad = Activity::query()
        ->where('log_name', 'duplicado_proyecto')
        ->latest()
        ->first();

    expect($actividad)->not->toBeNull();
    expect($actividad->properties->get('origen_codigo'))->toBe($origen->codigo);
    expect($actividad->properties->get('destino_codigo'))->toBe($resultado['proyecto_destino']->codigo);
});
