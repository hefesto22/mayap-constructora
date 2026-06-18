<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequisicionLinea>
 */
class RequisicionLineaFactory extends Factory
{
    protected $model = RequisicionLinea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requisicion_id'      => Requisicion::factory(),
            'item_id'             => Item::factory(),
            'cantidad_solicitada' => $this->faker->randomFloat(2, 1, 500),
            'cantidad_autorizada' => null,
            'cantidad_despachada' => 0,
            'cantidad_recibida'   => 0,
        ];
    }

    public function paraItem(Item $item): self
    {
        return $this->state(fn (): array => ['item_id' => $item->id]);
    }
}
