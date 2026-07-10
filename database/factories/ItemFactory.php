<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\Material;
use App\Models\UnidadMedida;
use App\Models\Zona;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id'            => null,
            'zona_id'                => Zona::factory(),
            'unidad_medida_id'       => UnidadMedida::factory(),
            'categoria'              => $this->faker->randomElement(CategoriaItem::cases()),
            'codigo'                 => strtoupper(Str::random(8)),
            'nombre'                 => $this->faker->sentence(3),
            'descripcion'            => null,
            'precio_unitario'        => $this->faker->randomFloat(2, 1, 5000),
            'desperdicio_porcentaje' => 0,
            'observaciones_precio'   => null,
            'activo'                 => true,
        ];
    }

    public function deCategoria(CategoriaItem $categoria): self
    {
        return $this->state(fn (): array => ['categoria' => $categoria]);
    }

    public function enZona(Zona $zona): self
    {
        return $this->state(fn (): array => ['zona_id' => $zona->id]);
    }

    public function conUnidad(UnidadMedida $unidad): self
    {
        return $this->state(fn (): array => ['unidad_medida_id' => $unidad->id]);
    }

    public function conPrecio(float $precio): self
    {
        return $this->state(fn (): array => ['precio_unitario' => $precio]);
    }

    public function inactivo(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }

    public function conMaterial(Material $material): self
    {
        return $this->state(fn (): array => ['material_id' => $material->id]);
    }
}
