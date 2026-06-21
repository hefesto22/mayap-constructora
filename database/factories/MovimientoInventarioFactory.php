<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoMovimientoInventario;
use App\Models\Bodega;
use App\Models\Material;
use App\Models\MovimientoInventario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovimientoInventario>
 *
 * Por defecto genera una entrada por compra hacia una bodega (destino),
 * que es el caso que respeta los CHECK de ubicación más simple.
 */
class MovimientoInventarioFactory extends Factory
{
    protected $model = MovimientoInventario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cantidad = $this->faker->randomFloat(4, 1, 200);
        $costo = $this->faker->randomFloat(4, 5, 500);

        return [
            'tipo'                => TipoMovimientoInventario::EntradaCompra,
            'material_id'         => Material::factory(),
            'bodega_origen_id'    => null,
            'proyecto_origen_id'  => null,
            'bodega_destino_id'   => Bodega::factory(),
            'proyecto_destino_id' => null,
            'cantidad'            => $cantidad,
            'costo_unitario'      => $costo,
            'valor_total'         => round($cantidad * $costo, 2),
            'motivo'              => null,
            'user_id'             => null,
            'fecha'               => now()->toDateString(),
        ];
    }

    public function deTipo(TipoMovimientoInventario $tipo): self
    {
        return $this->state(fn (): array => ['tipo' => $tipo]);
    }
}
