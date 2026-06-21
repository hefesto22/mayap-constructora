<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Existencia>
 *
 * Por defecto genera existencia en bodega física (cumple el CHECK de una
 * sola ubicación). Usar enObra() para stock de proyecto.
 */
class ExistenciaFactory extends Factory
{
    protected $model = Existencia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cantidad = $this->faker->randomFloat(4, 1, 500);
        $costo = $this->faker->randomFloat(2, 5, 500);

        return [
            'material_id' => Material::factory(),
            'bodega_id'   => Bodega::factory(),
            'proyecto_id' => null,
            'cantidad'    => $cantidad,
            'valor_total' => round($cantidad * $costo, 2),
        ];
    }

    public function enBodega(Bodega $bodega): self
    {
        return $this->state(fn (): array => [
            'bodega_id'   => $bodega->id,
            'proyecto_id' => null,
        ]);
    }

    public function enObra(Proyecto $proyecto): self
    {
        return $this->state(fn (): array => [
            'bodega_id'   => null,
            'proyecto_id' => $proyecto->id,
        ]);
    }

    public function sinStock(): self
    {
        return $this->state(fn (): array => [
            'cantidad'    => 0,
            'valor_total' => 0,
        ]);
    }
}
