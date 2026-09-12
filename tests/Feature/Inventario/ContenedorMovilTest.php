<?php

declare(strict_types=1);

use App\Exceptions\Inventario\MovimientoInvalidoException;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Inventario\RegistrarEntregaContenedorService;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;

/*
|--------------------------------------------------------------------------
| CONTENEDORES MÓVILES (Mauricio 2026-09-10).
|--------------------------------------------------------------------------
| "El agua de pipa es una pipa que siempre está llena en la bodega... hay
| que marcar cuándo sale y cuándo regresa."
|
| La idea que hace esto barato: con cuánto SALIÓ no se pregunta, es la
| existencia del contenedor. Solo se pregunta con cuánto volvió, y de esa
| resta sale lo que quedó en la obra — movido como cualquier despacho.
*/

beforeEach(function (): void {
    $this->inventario = app(RegistrarMovimientoService::class);
    $this->service = app(RegistrarEntregaContenedorService::class);

    $this->agua = Material::factory()->create(['nombre' => 'AGUA DE PIPA']);
    $this->obra = Proyecto::factory()->create();
    $this->quienRegistra = User::factory()->create(['is_active' => true]);

    $this->pipa = Bodega::factory()->contenedor($this->agua, capacidad: '10')->create();

    // La pipa arranca llena: 10 m³ comprados a L 50 cada uno.
    $this->llenarPipa = function (string $cantidad = '10'): void {
        $this->inventario->entradaCompra(
            $this->agua->id,
            Ubicacion::bodega($this->pipa->id),
            $cantidad,
            '50',
        );
    };
});

test('GOLDEN: la pipa sale llena, regresa vacía y lo que llevaba queda en la obra', function (): void {
    ($this->llenarPipa)();

    $entregado = $this->service->registrarRegreso(
        $this->pipa,
        $this->obra,
        '0',
        $this->quienRegistra->id,
    );

    expect($entregado)->toBe('10.0000');

    // La pipa quedó en cero — es la señal de "hay que llenarla".
    expect($this->pipa->refresh()->contenidoActual())->toBe('0.0000')
        ->and($this->pipa->estaVacio())->toBeTrue();

    // Y el agua está en la obra, no evaporada.
    $enObra = Existencia::query()
        ->where('material_id', $this->agua->id)
        ->where('proyecto_id', $this->obra->id)
        ->value('cantidad');

    expect((string) $enObra)->toBe('10.0000');
});

test('si regresa a la mitad, solo se descarga la diferencia', function (): void {
    ($this->llenarPipa)();

    $entregado = $this->service->registrarRegreso($this->pipa, $this->obra, '5');

    expect($entregado)->toBe('5.0000')
        ->and($this->pipa->refresh()->contenidoActual())->toBe('5.0000')
        ->and($this->pipa->porcentajeLleno())->toBe(50)
        ->and($this->pipa->estaVacio())->toBeFalse();
});

test('si regresa igual que salió no se mueve nada: el viaje se canceló', function (): void {
    ($this->llenarPipa)();

    $entregado = $this->service->registrarRegreso($this->pipa, $this->obra, '10');

    expect($entregado)->toBe('0')
        ->and($this->pipa->refresh()->contenidoActual())->toBe('10.0000');

    // Y la obra no recibió una existencia fantasma de cero.
    expect(Existencia::query()
        ->where('material_id', $this->agua->id)
        ->where('proyecto_id', $this->obra->id)
        ->exists())->toBeFalse();
});

test('regresar con MÁS de lo que llevaba se rechaza: o la rellenaron o el número está mal', function (): void {
    ($this->llenarPipa)('4');

    expect(fn () => $this->service->registrarRegreso($this->pipa, $this->obra, '9'))
        ->toThrow(MovimientoInvalidoException::class);

    expect($this->pipa->refresh()->contenidoActual())->toBe('4.0000');
});

test('una bodega fija no tiene regreso que marcar', function (): void {
    $fija = Bodega::factory()->create();

    expect(fn () => $this->service->registrarRegreso($fija, $this->obra, '0'))
        ->toThrow(MovimientoInvalidoException::class);
});

test('un contenedor sin material declarado no sabe qué entregó', function (): void {
    $sinMaterial = Bodega::factory()->contenedor($this->agua)->create();
    $sinMaterial->forceFill(['material_id' => null])->saveQuietly();

    expect(fn () => $this->service->registrarRegreso($sinMaterial->refresh(), $this->obra, '0'))
        ->toThrow(MovimientoInvalidoException::class);
});

test('un contenedor sin material declarado es de reparto: lleva lo que se le suba', function (): void {
    $volqueta = Bodega::factory()->create(['movil' => true, 'capacidad' => null]);

    expect($volqueta->esMovil())->toBeTrue()
        ->and($volqueta->esDeReparto())->toBeTrue()
        ->and($volqueta->esDeGranel())->toBeFalse()
        // Y la pipa es lo contrario: carga siempre lo mismo.
        ->and($this->pipa->esDeGranel())->toBeTrue()
        ->and($this->pipa->esDeReparto())->toBeFalse();
});

test('el porcentaje lleno no existe sin capacidad declarada', function (): void {
    $sinCapacidad = Bodega::factory()->contenedor($this->agua)->create();
    $sinCapacidad->forceFill(['capacidad' => null])->saveQuietly();

    expect($sinCapacidad->refresh()->porcentajeLleno())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| LA PUERTA DE VUELTA.
|--------------------------------------------------------------------------
| Un camión de reparto que regresa con lo que la obra no recibió. Sin esta
| salida el material quedaba bien contado pero atrapado arriba del camión,
| que es peor que no tenerlo registrado.
*/

test('lo que el camión trae de vuelta baja a bodega con su costo intacto', function (): void {
    $cemento = Material::factory()->create();
    $bodega = Bodega::factory()->create();
    $camion = Bodega::factory()->create(['movil' => true, 'material_id' => null, 'capacidad' => null]);

    // 40 sacos a L 250 salieron de bodega y 5 volvieron en el camión.
    $this->inventario->entradaCompra($cemento->id, Ubicacion::bodega($bodega->id), '40', '250');
    $this->inventario->traslado($cemento->id, Ubicacion::bodega($bodega->id), Ubicacion::bodega($camion->id), '5');

    $devuelto = $this->service->devolverABodega($camion, $bodega, $this->quienRegistra->id);

    expect($devuelto)->toHaveCount(1)
        ->and($devuelto[0]['cantidad'])->toBe('5.0000');

    $enCamion = Existencia::query()
        ->where('bodega_id', $camion->id)->where('material_id', $cemento->id)->value('cantidad');
    $enBodega = Existencia::query()
        ->where('bodega_id', $bodega->id)->where('material_id', $cemento->id)->first();

    expect((string) $enCamion)->toBe('0.0000')
        ->and((string) $enBodega->cantidad)->toBe('40.0000')
        // El costo promedio viaja intacto: nunca dejó de ser nuestro.
        // (El accessor lo redondea a 2 decimales, no a la escala 4 de
        // las cantidades.)
        ->and($enBodega->costo_promedio)->toBe('250.00');
});

test('devolver a otro camión no es devolver: la carga seguiría rodando', function (): void {
    $otroCamion = Bodega::factory()->create(['movil' => true, 'material_id' => null, 'capacidad' => null]);

    expect(fn () => $this->service->devolverABodega($this->pipa, $otroCamion))
        ->toThrow(MovimientoInvalidoException::class);
});

test('un camión vacío no tiene nada que devolver', function (): void {
    $bodega = Bodega::factory()->create();
    $camion = Bodega::factory()->create(['movil' => true, 'material_id' => null, 'capacidad' => null]);

    expect(fn () => $this->service->devolverABodega($camion, $bodega))
        ->toThrow(MovimientoInvalidoException::class);
});
