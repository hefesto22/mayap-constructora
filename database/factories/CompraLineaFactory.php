<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompraLinea>
 */
class CompraLineaFactory extends Factory
{
    protected $model = CompraLinea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cantidad = $this->faker->randomFloat(4, 1, 200);
        $costo = $this->faker->randomFloat(4, 5, 500);

        return [
            'compra_id'      => Compra::factory(),
            'material_id'    => Material::factory(),
            'cantidad'       => $cantidad,
            'costo_unitario' => $costo,
            'subtotal'       => round($cantidad * $costo, 2),
        ];
    }

    public function paraMaterial(Material $material): self
    {
        return $this->state(fn (): array => ['material_id' => $material->id]);
    }
}
