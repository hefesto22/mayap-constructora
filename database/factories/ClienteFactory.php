<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre'    => $this->faker->company(),
            'rtn'       => $this->faker->numerify('##############'),
            'telefono'  => $this->faker->numerify('####-####'),
            'email'     => $this->faker->safeEmail(),
            'direccion' => $this->faker->address(),
            'ciudad'    => $this->faker->city(),
            'notas'     => null,
            'activo'    => true,
        ];
    }

    /**
     * Cliente individual (persona natural) sin RTN registrado.
     */
    public function persona(): self
    {
        return $this->state(fn (): array => [
            'nombre' => $this->faker->name(),
            'rtn'    => null,
        ]);
    }

    /**
     * Cliente sin RTN — útil para tests que verifiquen el flujo
     * de cliente recién registrado al cotizar por primera vez.
     */
    public function sinRtn(): self
    {
        return $this->state(fn (): array => ['rtn' => null]);
    }

    public function inactivo(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }

    /**
     * Cliente con credito a N dias (default 30) — su cuenta por cobrar
     * vence a esos dias de la emision.
     */
    public function aCredito(int $dias = 30): self
    {
        return $this->state(fn (): array => [
            'condicion_pago' => 'credito',
            'dias_credito'   => $dias,
        ]);
    }

    public function conNombre(string $nombre): self
    {
        return $this->state(fn (): array => ['nombre' => $nombre]);
    }
}
