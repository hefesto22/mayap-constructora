<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ficha;
use App\Models\UnidadMedida;
use App\Models\Zona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ficha>
 */
class FichaFactory extends Factory
{
    protected $model = Ficha::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'zona_id'          => Zona::factory(),
            'unidad_medida_id' => UnidadMedida::factory(),
            // codigo se autogenera en el booted/creating event del modelo
            'nombre'              => $this->faker->sentence(4),
            'descripcion'         => null,
            'parametros_tecnicos' => null,
            'utilidad_porcentaje' => 25.00,
            'subtotal_cache'      => 0,
            'precio_venta_cache'  => 0,
            'precio_calculado_at' => null,
            'activa'              => true,
        ];
    }

    public function enZona(Zona $zona): self
    {
        return $this->state(fn (): array => ['zona_id' => $zona->id]);
    }

    public function conUnidad(UnidadMedida $unidad): self
    {
        return $this->state(fn (): array => ['unidad_medida_id' => $unidad->id]);
    }

    public function conUtilidad(float $porcentaje): self
    {
        return $this->state(fn (): array => ['utilidad_porcentaje' => $porcentaje]);
    }

    public function inactiva(): self
    {
        return $this->state(fn (): array => ['activa' => false]);
    }
}
