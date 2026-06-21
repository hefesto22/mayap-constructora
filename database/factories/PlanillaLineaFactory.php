<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoPago;
use App\Models\Empleado;
use App\Models\Planilla;
use App\Models\PlanillaLinea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanillaLinea>
 */
class PlanillaLineaFactory extends Factory
{
    protected $model = PlanillaLinea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dias = $this->faker->randomFloat(2, 1, 6);
        $tarifa = $this->faker->randomFloat(2, 300, 900);

        return [
            'planilla_id'     => Planilla::factory(),
            'empleado_id'     => Empleado::factory(),
            'proyecto_id'     => null,
            'tipo_pago'       => TipoPago::Jornal->value,
            'dias_trabajados' => $dias,
            'tarifa_aplicada' => $tarifa,
            'descripcion'     => null,
            'monto_bruto'     => round($dias * $tarifa, 2),
            'notas'           => null,
        ];
    }
}
