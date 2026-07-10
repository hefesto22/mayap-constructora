<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Proyecto;
use App\Models\ProyectoActividad;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ProyectoActividad>
 */
class ProyectoActividadFactory extends Factory
{
    protected $model = ProyectoActividad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proyecto_id' => Proyecto::factory(),
            'orden'       => 0,
            'nombre'      => $this->faker->randomElement([
                'ARRANQUE PLANTEL',
                'TRAZADO Y NIVELACIÓN',
                'EXCAVACIÓN',
                'FUNDICIÓN DE LOSA',
                'LEVANTAMIENTO DE PAREDES',
                'INSTALACIONES HIDROSANITARIAS',
                'ACABADOS',
                'LIMPIEZA FINAL',
            ]),
            'peso'             => null,
            'completada'       => false,
            'fecha_completada' => null,
            'notas'            => null,
        ];
    }

    public function paraProyecto(Proyecto $proyecto): self
    {
        return $this->state(fn (): array => ['proyecto_id' => $proyecto->id]);
    }

    public function completada(?Carbon $fecha = null): self
    {
        return $this->state(fn (): array => [
            'completada'       => true,
            'fecha_completada' => $fecha ?? Carbon::today(),
        ]);
    }

    public function conPeso(float|string $peso): self
    {
        return $this->state(fn (): array => ['peso' => (string) $peso]);
    }
}
