<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\Maquina;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bodega>
 */
class BodegaFactory extends Factory
{
    protected $model = Bodega::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // codigo se autogenera en el modelo (BOD-#####).
            'codigo'      => null,
            'nombre'      => 'BODEGA '.$this->faker->city(),
            'direccion'   => $this->faker->streetAddress(),
            'responsable' => $this->faker->name(),
            'activo'      => true,
            // Espejo del DEFAULT de la tabla: sin esto el modelo recién
            // creado no trae la columna y todo lo que pregunta si viaja
            // responde sobre un null.
            'movil' => false,
        ];
    }

    public function inactiva(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }

    /**
     * Un contenedor que viaja: la pipa de agua, la cisterna de diésel.
     * Sin material declarado no sabría qué entrega, así que va junto.
     */
    public function contenedor(Material $material, string $capacidad = '10', ?Maquina $maquina = null): self
    {
        return $this->state(fn (): array => [
            'nombre'      => 'PIPA DE '.$material->nombre,
            'movil'       => true,
            'capacidad'   => $capacidad,
            'material_id' => $material->id,
            'maquina_id'  => $maquina?->id,
        ]);
    }
}
