<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategoriaItem;
use App\Models\Material;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    protected $model = Material::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // codigo se autogenera en el modelo (MAT-##### / HE-#####).
            'unidad_medida_id' => UnidadMedida::factory(),
            'categoria'        => CategoriaItem::Materiales,
            'nombre'           => $this->faker->sentence(3),
            'descripcion'      => null,
            'activo'           => true,
        ];
    }

    public function deCategoria(CategoriaItem $categoria): self
    {
        return $this->state(fn (): array => ['categoria' => $categoria]);
    }

    public function conUnidad(UnidadMedida $unidad): self
    {
        return $this->state(fn (): array => ['unidad_medida_id' => $unidad->id]);
    }

    public function inactivo(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
