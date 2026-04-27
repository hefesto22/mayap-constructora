<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategoriaBaseLinea;
use App\Enums\CategoriaItem;
use App\Enums\TipoLineaFicha;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FichaLinea>
 */
class FichaLineaFactory extends Factory
{
    protected $model = FichaLinea::class;

    /**
     * Por default genera una línea tipo `item` — el caso más común.
     * Para líneas tipo `porcentaje` usar el state porcentaje().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ficha_id'               => Ficha::factory(),
            'tipo'                   => TipoLineaFicha::Item,
            'orden'                  => 0,
            'item_id'                => Item::factory(),
            'rendimiento'            => $this->faker->randomFloat(6, 0.001, 5),
            'desperdicio_porcentaje' => $this->faker->randomFloat(2, 0, 15),
            'descripcion'            => null,
            'porcentaje'             => null,
            'categoria_base'         => null,
            'categoria_destino'      => null,
            'notas'                  => null,
        ];
    }

    public function paraFicha(Ficha $ficha): self
    {
        return $this->state(fn (): array => ['ficha_id' => $ficha->id]);
    }

    public function conItem(Item $item): self
    {
        return $this->state(fn (): array => ['item_id' => $item->id]);
    }

    public function conRendimiento(float|string $rendimiento, float|string $desperdicio = 0): self
    {
        return $this->state(fn (): array => [
            'rendimiento'            => $rendimiento,
            'desperdicio_porcentaje' => $desperdicio,
        ]);
    }

    /**
     * Convierte el state en una línea tipo `porcentaje` válida.
     * Limpia los campos exclusivos de tipo=item para cumplir el CHECK.
     */
    public function porcentaje(
        string $descripcion,
        float|string $porcentaje,
        CategoriaBaseLinea $base,
        CategoriaItem $destino,
    ): self {
        return $this->state(fn (): array => [
            'tipo'                   => TipoLineaFicha::Porcentaje,
            'item_id'                => null,
            'rendimiento'            => null,
            'desperdicio_porcentaje' => null,
            'descripcion'            => $descripcion,
            'porcentaje'             => $porcentaje,
            'categoria_base'         => $base,
            'categoria_destino'      => $destino,
        ]);
    }
}
