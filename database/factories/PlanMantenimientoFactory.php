<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Maquina;
use App\Models\PlanMantenimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanMantenimiento>
 */
class PlanMantenimientoFactory extends Factory
{
    protected $model = PlanMantenimiento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'maquina_id'              => Maquina::factory(),
            'nombre'                  => 'CAMBIO DE '.$this->faker->unique()->randomElement(['ACEITE', 'PUNTAS', 'CUCHILLAS', 'FILTROS', 'LLANTAS']),
            'frecuencia_horas'        => 250,
            'frecuencia_km'           => null,
            'frecuencia_dias'         => null,
            'fecha_ultimo_cambio'     => today(),
            'horometro_ultimo_cambio' => 0,
            'km_ultimo_cambio'        => null,
            'ultimo_aviso_estado'     => null,
            'activo'                  => true,
            'notas'                   => null,
        ];
    }
}
