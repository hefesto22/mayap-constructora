<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UnidadMedida>
 */
class UnidadMedidaFactory extends Factory
{
    protected $model = UnidadMedida::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $codigo = strtoupper(Str::random(4));

        return [
            'codigo'  => $codigo,
            'nombre'  => $this->faker->words(2, true),
            'simbolo' => null,
            'activo'  => true,
        ];
    }

    public function inactiva(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
