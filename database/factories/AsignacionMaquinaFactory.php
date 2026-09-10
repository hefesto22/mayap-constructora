<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoAsignacion;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AsignacionMaquina>
 */
class AsignacionMaquinaFactory extends Factory
{
    protected $model = AsignacionMaquina::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'      => null, // auto ASMQ-#####
            'maquina_id'  => Maquina::factory(),
            'proyecto_id' => Proyecto::factory(),
            // Se pactan las cuatro: desde 2026-09-04 el parte cobra por la
            // dimensión de SU modalidad, y sin la tarifa pactada se niega a
            // registrarse. Un test que asigna una volqueta necesita la de
            // viaje aunque no la mire.
            'tarifa_hora_pactada'  => $this->faker->randomFloat(2, 500, 2500),
            'tarifa_viaje_pactada' => $this->faker->randomFloat(2, 300, 1500),
            'tarifa_km_pactada'    => $this->faker->randomFloat(2, 10, 60),
            'tarifa_flete_pactada' => $this->faker->randomFloat(2, 1000, 6000),
            'fecha_inicio'         => now()->startOfDay(),
            'fecha_fin'            => null,
            'estado'               => EstadoAsignacion::Activa->value,
            'notas'                => null,
        ];
    }

    public function finalizada(): self
    {
        return $this->state(fn (): array => [
            'estado'    => EstadoAsignacion::Finalizada->value,
            'fecha_fin' => now()->startOfDay(),
        ]);
    }
}
