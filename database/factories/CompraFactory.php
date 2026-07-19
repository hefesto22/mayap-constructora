<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Enums\TipoDocumentoFiscal;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Requisicion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Compra>
 */
class CompraFactory extends Factory
{
    protected $model = Compra::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'          => null, // auto COM-{AÑO}-#####
            'proveedor_id'    => Proveedor::factory(),
            'bodega_id'       => Bodega::factory(),
            'estado'          => EstadoCompra::Borrador->value,
            'condicion_pago'  => CondicionPago::Contado->value,
            'fecha'           => now()->startOfDay(),
            'fecha_recepcion' => null,
            // Factura con número por defecto: confirmar exige documento
            // fiscal declarado (y factura exige número) — los tests que
            // prueban lo contrario lo sobreescriben explícitamente.
            'numero_factura'        => strtoupper($this->faker->bothify('FAC-####-####')),
            'tipo_documento_fiscal' => TipoDocumentoFiscal::Factura->value,
            'aplica_isv'            => true,
            'isv_porcentaje'        => 15.00,
            'subtotal_cache'        => 0,
            'isv_cache'             => 0,
            'total_cache'           => 0,
            'notas'                 => null,
        ];
    }

    public function paraProveedor(Proveedor $proveedor): self
    {
        return $this->state(fn (): array => ['proveedor_id' => $proveedor->id]);
    }

    public function paraBodega(Bodega $bodega): self
    {
        return $this->state(fn (): array => ['bodega_id' => $bodega->id]);
    }

    public function enEstado(EstadoCompra $estado): self
    {
        return $this->state(fn (): array => ['estado' => $estado->value]);
    }

    public function aCredito(): self
    {
        return $this->state(fn (): array => ['condicion_pago' => CondicionPago::Credito->value]);
    }

    /**
     * Entrega directa a obra: destino = proyecto, sin bodega (XOR en DB).
     */
    public function directaAObra(Proyecto $proyecto): self
    {
        return $this->state(fn (): array => [
            'bodega_id'   => null,
            'proyecto_id' => $proyecto->id,
        ]);
    }

    public function paraRequisicion(Requisicion $requisicion): self
    {
        return $this->state(fn (): array => ['requisicion_id' => $requisicion->id]);
    }
}
