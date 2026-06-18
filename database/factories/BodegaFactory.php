<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bodega>
 */
class BodegaFactory extends Factory
{
    protected $model = Bodega::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // codigo se autogenera en el modelo (BOD-#####).
            'codigo'      => null,
            'nombre'      => 'BODEGA '.$this->faker->city(),
            'direccion'   => $this->faker->streetAddress(),
            'responsable' => $this->faker->name(),
            'activo'      => true,
        ];
    }

    public function inactiva(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
