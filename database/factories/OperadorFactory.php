<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Empleado;
use App\Models\Operador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operador>
 */
class OperadorFactory extends Factory
{
    protected $model = Operador::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre'      => mb_strtoupper($this->faker->unique()->name()),
            'empleado_id' => null,
            'telefono'    => $this->faker->optional()->numerify('9###-####'),
            'activo'      => true,
        ];
    }

    /**
     * Operador que además está en planilla.
     */
    public function dePlanilla(): self
    {
        return $this->state(fn (): array => [
            'empleado_id' => Empleado::factory(),
        ]);
    }
}
