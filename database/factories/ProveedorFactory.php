<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CondicionPago;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'         => null, // auto PRV-#####
            'nombre'         => 'PROVEEDOR '.$this->faker->company(),
            'rtn'            => null,
            'telefono'       => $this->faker->numerify('####-####'),
            'email'          => null,
            'direccion'      => $this->faker->streetAddress(),
            'ciudad'         => $this->faker->city(),
            'condicion_pago' => CondicionPago::Contado->value,
            'dias_credito'   => 0,
            'notas'          => null,
            'activo'         => true,
        ];
    }

    public function aCredito(int $dias = 30): self
    {
        return $this->state(fn (): array => [
            'condicion_pago' => CondicionPago::Credito->value,
            'dias_credito'   => $dias,
        ]);
    }

    public function inactivo(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
