<?php

declare(strict_types=1);

use App\Enums\CategoriaCompra;
use App\Enums\CategoriaItem;
use App\Enums\EstadoRequisicion;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Services\Compras\ConfirmarCompraService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Compras MIXTAS (destino por línea) + flete/descuento prorrateados.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(ConfirmarCompraService::class);
    $this->bodega = Bodega::factory()->create();
    $this->proyecto = Proyecto::factory()->create();
});

test('GOLDEN: una factura reparte líneas entre bodega y obra (200 bolsas: 100 y 100)', function (): void {
    $cemento = Material::factory()->create(['nombre' => 'CEMENTO GRIS']);

    // Cabecera a bodega; una línea override hacia la obra.
    $compra = Compra::factory()->paraBodega($this->bodega)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $cemento->id,
        'cantidad'  => 100, 'costo_unitario' => 220,
    ]);
    CompraLinea::factory()->create([
        'compra_id'   => $compra->id, 'material_id' => $cemento->id,
        'cantidad'    => 100, 'costo_unitario' => 220,
        'proyecto_id' => $this->proyecto->id, // esta línea va DIRECTO a obra
    ]);

    $this->service->confirmar($compra);

    $enBodega = Existencia::query()
        ->where('material_id', $cemento->id)->where('bodega_id', $this->bodega->id)->firstOrFail();
    $enObra = Existencia::query()
        ->where('material_id', $cemento->id)->where('proyecto_id', $this->proyecto->id)->firstOrFail();

    expect($enBodega->cantidad)->toBe('100.0000')
        ->and($enObra->cantidad)->toBe('100.0000')
        ->and($compra->fresh()->subtotal_cache)->toBe('44000.00');
});

test('el flete se prorratea por valor y capitaliza al costo del inventario', function (): void {
    $materialA = Material::factory()->create();
    $materialB = Material::factory()->create();

    // A: 100×10=1000 (2/3 del valor) · B: 50×10=500 (1/3) · flete 300.
    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv' => false, 'isv_porcentaje' => 0, 'costo_envio' => 300,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $materialA->id, 'cantidad' => 100, 'costo_unitario' => 10,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $materialB->id, 'cantidad' => 50, 'costo_unitario' => 10,
    ]);

    $this->service->confirmar($compra);

    $stockA = Existencia::query()->where('material_id', $materialA->id)->firstOrFail();
    $stockB = Existencia::query()->where('material_id', $materialB->id)->firstOrFail();

    // A absorbe 200 de flete → 1200/100 = 12.00 · B absorbe 100 → 600/50 = 12.00.
    expect($stockA->costo_promedio)->toBe('12.00')
        ->and($stockB->costo_promedio)->toBe('12.00')
        // El valor total del inventario conserva el flete completo al céntimo.
        ->and(bcadd((string) $stockA->valor_total, (string) $stockB->valor_total, 2))->toBe('1800.00');

    // CxP/total: base 1500 + 300 flete = 1800 (sin ISV).
    expect($compra->fresh()->total_cache)->toBe('1800.00');
});

test('el descuento global resta del costo capitalizado y del total', function (): void {
    $material = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv' => false, 'isv_porcentaje' => 0, 'descuento' => 100,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 100, 'costo_unitario' => 10,
    ]);

    $this->service->confirmar($compra);

    $stock = Existencia::query()->where('material_id', $material->id)->firstOrFail();

    // 1000 − 100 = 900 → costo 9.00.
    expect($stock->costo_promedio)->toBe('9.00')
        ->and($compra->fresh()->total_cache)->toBe('900.00');
});

test('el ISV grava subtotal + flete − descuento', function (): void {
    $material = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv' => true, 'isv_porcentaje' => 15, 'costo_envio' => 200, 'descuento' => 100,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 100, 'costo_unitario' => 10,
    ]);

    $this->service->confirmar($compra);
    $compra->refresh();

    // Base = 1000 + 200 − 100 = 1100; ISV 165; total 1265.
    expect($compra->subtotal_cache)->toBe('1000.00')
        ->and($compra->isv_cache)->toBe('165.00')
        ->and($compra->total_cache)->toBe('1265.00');
});

test('rechaza confirmar si el descuento deja líneas con valor negativo', function (): void {
    $material = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'aplica_isv' => false, 'isv_porcentaje' => 0, 'descuento' => 5000,
    ]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id, 'cantidad' => 100, 'costo_unitario' => 10,
    ]);

    expect(fn () => $this->service->confirmar($compra))
        ->toThrow(CompraNoConfirmableException::class);
});

test('con requisición enlazada, solo las líneas que van a SU obra despachan', function (): void {
    $cemento = Material::factory()->create();

    $requisicion = Requisicion::factory()
        ->paraProyecto($this->proyecto)
        ->enEstado(EstadoRequisicion::RequisicionCompra)
        ->create();
    RequisicionLinea::factory()->paraMaterial($cemento)->create([
        'requisicion_id'      => $requisicion->id,
        'cantidad_solicitada' => '200.0000',
        'cantidad_autorizada' => '200.0000',
    ]);

    // Cabecera a bodega; solo 100 van directo a la obra de la requisición.
    $compra = Compra::factory()
        ->paraBodega($this->bodega)
        ->paraRequisicion($requisicion)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $cemento->id,
        'cantidad'  => 100, 'costo_unitario' => 220,
    ]);
    CompraLinea::factory()->create([
        'compra_id'   => $compra->id, 'material_id' => $cemento->id,
        'cantidad'    => 100, 'costo_unitario' => 220,
        'proyecto_id' => $this->proyecto->id,
    ]);

    $this->service->confirmar($compra);

    $linea = $requisicion->fresh()->lineas()->firstOrFail();

    // Solo las 100 directas cuentan como despachadas; las de bodega no.
    expect($linea->cantidad_despachada)->toBe('100.0000')
        ->and($requisicion->fresh()->estado)->toBe(EstadoRequisicion::Despachada);
});

test('la DB rechaza una línea con bodega Y obra al mismo tiempo', function (): void {
    $compra = Compra::factory()->paraBodega($this->bodega)->create();

    expect(fn () => CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => Material::factory()->create()->id,
        'cantidad'       => 1,
        'costo_unitario' => 1,
        'bodega_id'      => $this->bodega->id,
        'proyecto_id'    => $this->proyecto->id,
    ]))->toThrow(QueryException::class);
});

test('MEZCLA catalogo + libre: el material entra a inventario, la linea libre es gasto directo y todo suma al total', function (): void {
    // Decisión Mauricio 2026-07-20: una factura real trae de todo — las
    // líneas libres acompañan al catálogo en la MISMA compra.
    $bodega = Bodega::factory()->create();
    $material = Material::factory()->create();

    $compra = Compra::factory()->paraBodega($bodega)->create([
        'aplica_isv'     => true,
        'isv_porcentaje' => 15.00,
    ]);

    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => $material->id,
        'cantidad'       => 10,
        'costo_unitario' => 100,
    ]);
    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => null,
        'descripcion'    => 'CARRETILLA DE MANO',
        'cantidad'       => 1,
        'costo_unitario' => 500,
    ]);

    app(ConfirmarCompraService::class)->confirmar($compra);
    $compra->refresh();

    // Neto 1,000 + 500 = 1,500; ISV 15% = 225; total 1,725.
    expect($compra->subtotal_cache)->toBe('1500.00')
        ->and($compra->isv_cache)->toBe('225.00')
        ->and($compra->total_cache)->toBe('1725.00');

    // Inventario: SOLO el material del catálogo; la carretilla es gasto.
    expect(Existencia::query()->where('bodega_id', $bodega->id)->count())->toBe(1)
        ->and(Existencia::query()->where('material_id', $material->id)->value('cantidad'))->toBe('10.0000');
});

test('EQUIPO con catalogo HE-: la herramienta comprada entra a inventario al confirmar', function (): void {
    $bodega = Bodega::factory()->create();
    $herramienta = Material::factory()->create([
        'categoria' => CategoriaItem::HerramientaEquipo,
    ]);

    $compra = Compra::factory()->paraBodega($bodega)->create([
        'categoria'      => CategoriaCompra::EquipoConstruccion,
        'aplica_isv'     => true,
        'isv_porcentaje' => 15.00,
    ]);

    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => $herramienta->id,
        'cantidad'       => 4,
        'costo_unitario' => 250,
    ]);

    app(ConfirmarCompraService::class)->confirmar($compra);

    expect(Existencia::query()
        ->where('bodega_id', $bodega->id)
        ->where('material_id', $herramienta->id)
        ->value('cantidad'))->toBe('4.0000');
});
