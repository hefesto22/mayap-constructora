<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Enums\TipoDocumentoFiscal;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Material;
use App\Services\Compras\ConfirmarCompraService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Golden tests del documento fiscal de compras: confirmar exige declarar
| qué emitió el proveedor (factura / recibo por honorarios / boleta /
| ninguno) y la factura exige su número. El ISV es independiente.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(ConfirmarCompraService::class);
    $this->bodega = Bodega::factory()->create();
});

/**
 * @param array<string, mixed> $attrs
 */
function compraConLinea(Bodega $bodega, array $attrs): Compra
{
    $compra = Compra::factory()->paraBodega($bodega)->create($attrs);

    CompraLinea::factory()->create([
        'compra_id'      => $compra->id,
        'material_id'    => Material::factory()->create()->id,
        'cantidad'       => 10,
        'costo_unitario' => 100,
    ]);

    return $compra;
}

test('sin declarar el documento fiscal la compra no se confirma', function (): void {
    $compra = compraConLinea($this->bodega, [
        'tipo_documento_fiscal' => null,
        'numero_factura'        => null,
    ]);

    expect(fn () => $this->service->confirmar($compra))
        ->toThrow(CompraNoConfirmableException::class, 'documento');

    expect($compra->refresh()->estado)->toBe(EstadoCompra::Borrador);
});

test('factura sin número no se confirma', function (): void {
    $compra = compraConLinea($this->bodega, [
        'tipo_documento_fiscal' => TipoDocumentoFiscal::Factura->value,
        'numero_factura'        => null,
    ]);

    expect(fn () => $this->service->confirmar($compra))
        ->toThrow(CompraNoConfirmableException::class, 'número');
});

test('factura con número se confirma normal', function (): void {
    $compra = compraConLinea($this->bodega, [
        'tipo_documento_fiscal' => TipoDocumentoFiscal::Factura->value,
        'numero_factura'        => 'FAC-001-2026',
    ]);

    $this->service->confirmar($compra);

    expect($compra->refresh()->estado)->toBe(EstadoCompra::Confirmada);
});

test('recibo, boleta y ninguno confirman sin exigir número', function (string $tipo): void {
    $compra = compraConLinea($this->bodega, [
        'tipo_documento_fiscal' => $tipo,
        'numero_factura'        => null,
    ]);

    $this->service->confirmar($compra);

    expect($compra->refresh()->estado)->toBe(EstadoCompra::Confirmada);
})->with([
    'recibo por honorarios' => ['recibo_honorarios'],
    'boleta de compra'      => ['boleta_compra'],
    'ninguno'               => ['ninguno'],
]);

test('el tipo persiste con su cast de enum', function (): void {
    $compra = Compra::factory()->paraBodega($this->bodega)->create([
        'tipo_documento_fiscal' => 'boleta_compra',
    ]);

    expect($compra->refresh()->tipo_documento_fiscal)->toBe(TipoDocumentoFiscal::BoletaCompra);
});

test('el CHECK de la base rechaza un tipo inventado', function (): void {
    $compra = Compra::factory()->paraBodega($this->bodega)->create();

    // Update crudo: el cast del enum lanzaría ValueError antes de llegar
    // a la base — aquí se prueba el CHECK de Postgres, no el cast.
    expect(fn () => DB::table('compras')
        ->where('id', $compra->id)
        ->update(['tipo_documento_fiscal' => 'SERVILLETA_FIRMADA']))
        ->toThrow(QueryException::class);
});
