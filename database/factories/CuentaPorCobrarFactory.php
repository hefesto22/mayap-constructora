<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoCuentaPorCobrar;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuentaPorCobrar>
 */
class CuentaPorCobrarFactory extends Factory
{
    protected $model = CuentaPorCobrar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monto = $this->faker->randomFloat(2, 1000, 200000);
        $emision = now()->startOfDay();

        return [
            'codigo'            => null, // auto CXC-{AÑO}-#####
            'cliente_id'        => Cliente::factory(),
            'proyecto_id'       => null,
            'concepto'          => 'ANTICIPO DE OBRA',
            'monto_original'    => $monto,
            'saldo'             => $monto,
            'fecha_emision'     => $emision,
            'fecha_vencimiento' => $emision->copy()->addDays(30),
            'estado'            => EstadoCuentaPorCobrar::Pendiente->value,
            'notas'             => null,
        ];
    }
}
