<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AsignacionMaquina;
use App\Models\ConsumoCombustible;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsumoCombustible>
 */
class ConsumoCombustibleFactory extends Factory
{
    protected $model = ConsumoCombustible::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $litros = $this->faker->randomFloat(2, 10, 200);
        $precio = $this->faker->randomFloat(4, 90, 130);

        return [
            'codigo'                => null, // auto COMB-{AÑO}-#####
            'asignacion_maquina_id' => AsignacionMaquina::factory(),
            'fecha'                 => now()->startOfDay(),
            'cantidad_litros'       => $litros,
            'precio_litro'          => $precio,
            'costo_cache'           => round($litros * $precio, 2),
            'operador'              => $this->faker->name(),
            'notas'                 => null,
            'user_id'               => null,
        ];
    }
}
