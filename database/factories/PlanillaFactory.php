<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoPlanilla;
use App\Enums\Periodicidad;
use App\Models\Planilla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Planilla>
 */
class PlanillaFactory extends Factory
{
    protected $model = Planilla::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inicio = now()->startOfWeek();

        return [
            'codigo'       => null, // auto PLA-{AÑO}-#####
            'periodicidad' => Periodicidad::Semanal->value,
            'fecha_inicio' => $inicio,
            'fecha_fin'    => $inicio->copy()->addDays(6),
            'estado'       => EstadoPlanilla::Borrador->value,
            'total_cache'  => 0,
            'notas'        => null,
        ];
    }

    public function cerrada(): self
    {
        return $this->state(fn (): array => ['estado' => EstadoPlanilla::Cerrada->value]);
    }
}
