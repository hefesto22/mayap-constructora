<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cobro;
use App\Models\CuentaPorCobrar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cobro>
 */
class CobroFactory extends Factory
{
    protected $model = Cobro::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cuenta_por_cobrar_id' => CuentaPorCobrar::factory(),
            'monto'                => $this->faker->randomFloat(2, 100, 5000),
            'fecha'                => now()->startOfDay(),
            'metodo'               => 'TRANSFERENCIA',
            'referencia'           => null,
            'user_id'              => null,
            'notas'                => null,
        ];
    }
}
