<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgendaMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgendaMaquina>
 */
class AgendaMaquinaFactory extends Factory
{
    protected $model = AgendaMaquina::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'maquina_id'      => Maquina::factory(),
            'proyecto_id'     => Proyecto::factory(),
            'fecha'           => $this->faker->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'horas_previstas' => '8.00',
            'notas'           => null,
            'user_id'         => null,
        ];
    }

    public function elDia(string $fecha): self
    {
        return $this->state(fn (): array => ['fecha' => $fecha]);
    }
}
