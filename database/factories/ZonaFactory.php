<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Zona;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Zona>
 */
class ZonaFactory extends Factory
{
    protected $model = Zona::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'      => strtoupper(Str::random(3)),
            'nombre'      => $this->faker->city(),
            'descripcion' => null,
            'activa'      => true,
        ];
    }

    public function inactiva(): self
    {
        return $this->state(fn (): array => ['activa' => false]);
    }
}
