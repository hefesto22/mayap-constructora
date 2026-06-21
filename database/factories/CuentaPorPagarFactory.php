<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoCuentaPorPagar;
use App\Models\Compra;
use App\Models\CuentaPorPagar;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuentaPorPagar>
 */
class CuentaPorPagarFactory extends Factory
{
    protected $model = CuentaPorPagar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monto = $this->faker->randomFloat(2, 100, 50000);
        $emision = now()->startOfDay();

        return [
            'compra_id'         => Compra::factory(),
            'proveedor_id'      => Proveedor::factory(),
            'monto_original'    => $monto,
            'saldo'             => $monto,
            'fecha_emision'     => $emision,
            'fecha_vencimiento' => $emision->copy()->addDays(30),
            'estado'            => EstadoCuentaPorPagar::Pendiente->value,
        ];
    }
}
