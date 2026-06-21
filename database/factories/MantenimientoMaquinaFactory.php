<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoMantenimiento;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MantenimientoMaquina>
 */
class MantenimientoMaquinaFactory extends Factory
{
    protected $model = MantenimientoMaquina::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'                   => null, // auto MANT-{AÑO}-#####
            'maquina_id'               => Maquina::factory()->enMantenimiento(),
            'fecha_inicio'             => now()->startOfDay(),
            'fecha_fin'                => null,
            'motivo'                   => 'FALLA HIDRÁULICA',
            'asignacion_finalizada_id' => null,
            'asignacion_sustituta_id'  => null,
            'estado'                   => EstadoMantenimiento::EnProceso->value,
            'notas'                    => null,
        ];
    }

    public function finalizado(): self
    {
        return $this->state(fn (): array => [
            'estado'    => EstadoMantenimiento::Finalizado->value,
            'fecha_fin' => now()->startOfDay(),
        ]);
    }
}
