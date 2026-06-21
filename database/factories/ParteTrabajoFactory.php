<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MetodoCapturaHoras;
use App\Models\AsignacionMaquina;
use App\Models\ParteTrabajo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParteTrabajo>
 */
class ParteTrabajoFactory extends Factory
{
    protected $model = ParteTrabajo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $horas = $this->faker->randomFloat(2, 1, 8);
        $tarifa = $this->faker->randomFloat(2, 500, 2500);

        return [
            'codigo'                => null, // auto PART-{AÑO}-#####
            'asignacion_maquina_id' => AsignacionMaquina::factory(),
            'fecha'                 => now()->startOfDay(),
            'metodo_captura'        => MetodoCapturaHoras::Manual->value,
            'lectura_inicial'       => null,
            'lectura_final'         => null,
            'horas'                 => $horas,
            'horas_extra'           => 0,
            'motivo_horas_extra'    => null,
            'tarifa_hora_aplicada'  => $tarifa,
            'costo_cache'           => round($horas * $tarifa, 2),
            'operador'              => $this->faker->name(),
            'notas'                 => null,
            'user_id'               => null,
        ];
    }

    public function porHorometro(float $inicial, float $final): self
    {
        return $this->state(fn (): array => [
            'metodo_captura'  => MetodoCapturaHoras::Horometro->value,
            'lectura_inicial' => $inicial,
            'lectura_final'   => $final,
            'horas'           => round($final - $inicial, 2),
        ]);
    }
}
