<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrigenGastoReparacion;
use App\Models\GastoMantenimiento;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GastoMantenimiento>
 */
class GastoMantenimientoFactory extends Factory
{
    protected $model = GastoMantenimiento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $maquina = Maquina::factory();

        return [
            'mantenimiento_id' => MantenimientoMaquina::factory(),
            'maquina_id'       => $maquina,
            'fecha'            => today()->toDateString(),
            'descripcion'      => 'REPUESTO DE PRUEBA',
            'monto'            => '1500.00',
            'origen'           => OrigenGastoReparacion::Recepcion->value,
            'conciliado_at'    => now(),
        ];
    }

    /**
     * Anotado por la obra: falta que recepción lo respalde.
     */
    public function deLaObra(): self
    {
        return $this->state(fn (): array => [
            'origen'        => OrigenGastoReparacion::Encargado->value,
            'conciliado_at' => null,
        ]);
    }
}
