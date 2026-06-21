<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoMaquina;
use App\Enums\TipoMaquina;
use App\Models\Maquina;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Maquina>
 */
class MaquinaFactory extends Factory
{
    protected $model = Maquina::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'           => null, // auto MAQ-#####
            'nombre'           => 'MAQUINA '.$this->faker->randomElement(['CAT', 'KOMATSU', 'JCB', 'CASE']).' '.$this->faker->numerify('###'),
            'tipo'             => $this->faker->randomElement(TipoMaquina::cases())->value,
            'marca'            => $this->faker->randomElement(['CATERPILLAR', 'KOMATSU', 'JCB', 'CASE']),
            'modelo'           => $this->faker->bothify('??-###'),
            'anio'             => $this->faker->numberBetween(2010, 2025),
            'serie'            => $this->faker->bothify('SN-#####'),
            'horometro_actual' => $this->faker->randomFloat(2, 0, 5000),
            'tarifa_hora'      => $this->faker->randomFloat(2, 500, 2500),
            'jornada_horas'    => 8,
            'estado'           => EstadoMaquina::Disponible->value,
            'notas'            => null,
            'activo'           => true,
        ];
    }

    public function asignada(): self
    {
        return $this->state(fn (): array => ['estado' => EstadoMaquina::Asignada->value]);
    }

    public function enMantenimiento(): self
    {
        return $this->state(fn (): array => ['estado' => EstadoMaquina::Mantenimiento->value]);
    }

    public function deBaja(): self
    {
        return $this->state(fn (): array => ['estado' => EstadoMaquina::Baja->value]);
    }

    public function inactiva(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
