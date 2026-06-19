<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Item;
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
            'item_id'        => Item::factory(),
            'cantidad'       => $cantidad,
            'costo_unitario' => $costo,
            'subtotal'       => round($cantidad * $costo, 2),
        ];
    }

    public function paraItem(Item $item): self
    {
        return $this->state(fn (): array => ['item_id' => $item->id]);
    }
}
