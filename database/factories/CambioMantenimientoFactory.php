<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CambioMantenimiento;
use App\Models\PlanMantenimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CambioMantenimiento>
 */
class CambioMantenimientoFactory extends Factory
{
    protected $model = CambioMantenimiento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_mantenimiento_id' => PlanMantenimiento::factory(),
            'fecha'                 => today(),
            'horometro'             => $this->faker->randomFloat(2, 0, 5000),
            'kilometraje'           => null,
            'notas'                 => null,
            'user_id'               => null,
        ];
    }
}
