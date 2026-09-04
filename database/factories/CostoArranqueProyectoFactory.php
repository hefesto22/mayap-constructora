<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RubroCostoArranque;
use App\Models\CostoArranqueProyecto;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostoArranqueProyecto>
 */
class CostoArranqueProyectoFactory extends Factory
{
    protected $model = CostoArranqueProyecto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proyecto_id' => Proyecto::factory(),
            'rubro'       => RubroCostoArranque::Materiales->value,
            'monto'       => $this->faker->randomFloat(2, 500, 50000),
            // Relativa a hoy: una fecha literal caduca contra los CHECK y
            // contra la propia lectura del test dentro de unos meses.
            'fecha'       => today()->subMonths(3)->toDateString(),
            'descripcion' => 'HIERRO 3/8, FERRETERIA EL SOL',
        ];
    }

    public function delRubro(RubroCostoArranque $rubro): self
    {
        return $this->state(fn (): array => ['rubro' => $rubro->value]);
    }

    public function porMonto(string $monto): self
    {
        return $this->state(fn (): array => ['monto' => $monto]);
    }
}
