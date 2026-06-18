<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoRequisicion;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Requisicion>
 */
class RequisicionFactory extends Factory
{
    protected $model = Requisicion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $solicitud = now()->startOfDay();

        return [
            // codigo se autogenera en el modelo (REQ-{AÑO}-#####).
            'codigo'          => null,
            'proyecto_id'     => Proyecto::factory(),
            'estado'          => EstadoRequisicion::Solicitada->value,
            'solicitante_id'  => User::factory(),
            'fecha_solicitud' => $solicitud,
            'fecha_necesaria' => $solicitud->copy()->addDays(5),
            'notas'           => null,
        ];
    }

    public function enEstado(EstadoRequisicion $estado): self
    {
        return $this->state(fn (): array => ['estado' => $estado->value]);
    }

    public function paraProyecto(Proyecto $proyecto): self
    {
        return $this->state(fn (): array => ['proyecto_id' => $proyecto->id]);
    }
}
