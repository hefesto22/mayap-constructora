<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoPago;
use App\Models\Empleado;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Empleado>
 */
class EmpleadoFactory extends Factory
{
    protected $model = Empleado::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'      => null, // auto EMP-#####
            'nombre'      => $this->faker->name(),
            'identidad'   => $this->faker->numerify('08011990#####'),
            'cargo'       => $this->faker->randomElement(['ALBAÑIL', 'AYUDANTE', 'MAESTRO DE OBRA', 'OPERADOR']),
            'tipo_pago'   => TipoPago::Jornal->value,
            'tarifa_base' => $this->faker->randomFloat(2, 300, 900),
            'notas'       => null,
            'activo'      => true,
        ];
    }

    public function salario(float $monto = 5000): self
    {
        return $this->state(fn (): array => [
            'tipo_pago'   => TipoPago::Salario->value,
            'tarifa_base' => $monto,
        ]);
    }

    public function destajo(): self
    {
        return $this->state(fn (): array => [
            'tipo_pago'   => TipoPago::Destajo->value,
            'tarifa_base' => 0,
        ]);
    }

    public function inactivo(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
