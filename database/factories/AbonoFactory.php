<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Abono;
use App\Models\CuentaPorPagar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Abono>
 */
class AbonoFactory extends Factory
{
    protected $model = Abono::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cuenta_por_pagar_id' => CuentaPorPagar::factory(),
            'monto'               => $this->faker->randomFloat(2, 10, 1000),
            'fecha'               => now()->startOfDay(),
            'metodo'              => 'EFECTIVO',
            'referencia'          => null,
            'foto_comprobante'    => null,
            'user_id'             => null,
            'notas'               => null,
        ];
    }
}
