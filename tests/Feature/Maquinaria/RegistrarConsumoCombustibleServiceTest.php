<?php

declare(strict_types=1);

use App\Exceptions\Maquinaria\CombustibleInvalidoException;
use App\Models\ConsumoCombustible;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\RegistrarConsumoCombustibleService;

/*
|--------------------------------------------------------------------------
| Golden tests del consumo de combustible cargado a la obra.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = new RegistrarConsumoCombustibleService;
    $this->asignar = new AsignarMaquinaService;
    $this->obra = Proyecto::factory()->create();
    $maquina = Maquina::factory()->create();
    $this->asignacion = $this->asignar->asignar($maquina, $this->obra->id);
});

test('GOLDEN: registrar combustible calcula el costo y lo carga a la obra', function (): void {
    // 50 litros × 110.50 = 5,525.00
    $consumo = $this->service->registrar($this->asignacion, litros: '50', precioLitro: '110.50');

    expect($consumo->codigo)->toStartWith('COMB-')
        ->and($consumo->cantidad_litros)->toBe('50.00')
        ->and($consumo->precio_litro)->toBe('110.5000')
        ->and($consumo->costo_cache)->toBe('5525.00');
});

test('no se puede registrar combustible con litros cero o negativos', function (): void {
    expect(fn () => $this->service->registrar($this->asignacion, litros: '0', precioLitro: '110'))
        ->toThrow(CombustibleInvalidoException::class);

    expect(ConsumoCombustible::query()->count())->toBe(0);
});

test('no se puede registrar combustible en una asignación finalizada', function (): void {
    $this->asignar->finalizar($this->asignacion);

    expect(fn () => $this->service->registrar($this->asignacion->fresh(), litros: '40', precioLitro: '110'))
        ->toThrow(CombustibleInvalidoException::class);
});

test('el costo de combustible de una obra es la suma de sus consumos', function (): void {
    $this->service->registrar($this->asignacion, litros: '50', precioLitro: '100'); // 5,000
    $this->service->registrar($this->asignacion, litros: '30', precioLitro: '100'); // 3,000

    $suma = ConsumoCombustible::query()
        ->where('asignacion_maquina_id', $this->asignacion->id)
        ->sum('costo_cache');

    expect((float) $suma)->toBe(8000.0);
});
