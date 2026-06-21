<?php

declare(strict_types=1);

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Material;
use App\Models\Proveedor;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Tests de la capa de datos de Compras (modelos + CHECK + relaciones).
|--------------------------------------------------------------------------
*/

test('compra persiste con casts correctos', function (): void {
    $compra = Compra::factory()->aCredito()->enEstado(EstadoCompra::Confirmada)->create([
        'fecha' => '2026-06-18',
    ]);

    expect($compra->estado)->toBe(EstadoCompra::Confirmada)
        ->and($compra->condicion_pago)->toBe(CondicionPago::Credito)
        ->and($compra->aplica_isv)->toBeTrue()
        ->and($compra->fecha->format('Y-m-d'))->toBe('2026-06-18');
});

test('auto-código COM-AÑO-##### se reinicia por año', function (): void {
    Compra::factory()->create(['fecha' => '2026-05-01']);
    Compra::factory()->create(['fecha' => '2026-05-02']);
    $compra2027 = Compra::factory()->create(['fecha' => '2027-01-10']);

    expect($compra2027->codigo)->toBe('COM-2027-00001')
        ->and(Compra::query()->where('codigo', 'COM-2026-00002')->exists())->toBeTrue();
});

test('mutator uppercase aplica a notas', function (): void {
    $compra = Compra::factory()->create(['notas' => 'compra urgente de cemento']);

    expect($compra->notas)->toBe('COMPRA URGENTE DE CEMENTO');
});

test('relaciones proveedor, bodega y líneas funcionan', function (): void {
    $compra = Compra::factory()->create();
    CompraLinea::factory()->count(2)->create(['compra_id' => $compra->id]);

    expect($compra->proveedor)->toBeInstanceOf(Proveedor::class)
        ->and($compra->lineas)->toHaveCount(2);
});

test('CHECK rechaza estado inválido a nivel DB', function (): void {
    Compra::factory()->create(['estado' => 'pagada']);
})->throws(ValueError::class);

test('CHECK rechaza cantidad de línea cero o negativa', function (): void {
    CompraLinea::factory()->create(['cantidad' => 0]);
})->throws(QueryException::class);

test('un material no se repite dentro de la misma compra', function (): void {
    $compra = Compra::factory()->create();
    $material = Material::factory()->create();

    CompraLinea::factory()->create(['compra_id' => $compra->id, 'material_id' => $material->id]);
    CompraLinea::factory()->create(['compra_id' => $compra->id, 'material_id' => $material->id]);
})->throws(QueryException::class);

test('borrar la compra arrastra sus líneas (cascade)', function (): void {
    $compra = Compra::factory()->create();
    CompraLinea::factory()->count(3)->create(['compra_id' => $compra->id]);

    $compra->forceDelete();

    expect(CompraLinea::query()->where('compra_id', $compra->id)->count())->toBe(0);
});

test('FK restrict: proveedor con compras no se puede eliminar', function (): void {
    $proveedor = Proveedor::factory()->create();
    Compra::factory()->paraProveedor($proveedor)->create();

    $proveedor->forceDelete();
})->throws(QueryException::class);
