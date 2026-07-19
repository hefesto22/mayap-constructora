<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UnidadRenta;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProyectoLineaRenta>
 */
class ProyectoLineaRentaFactory extends Factory
{
    protected $model = ProyectoLineaRenta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proyecto_id'     => Proyecto::factory(),
            'maquina_id'      => Maquina::factory(),
            'orden'           => 1,
            'unidad'          => UnidadRenta::Hora,
            'cantidad'        => '8.00',
            'tarifa_snapshot' => $this->faker->randomFloat(2, 500, 2000),
            'fecha_llegada'   => now()->addDays(3)->toDateString(),
            'hora_llegada'    => '07:00',
            'es_extension'    => false,
            'notas'           => null,
        ];
    }

    /**
     * Línea cobrada por días (la tarifa pasa a ser diaria).
     */
    public function porDia(): self
    {
        return $this->state(fn (): array => [
            'unidad'          => UnidadRenta::Dia,
            'cantidad'        => '2.00',
            'tarifa_snapshot' => $this->faker->randomFloat(2, 4000, 16000),
        ]);
    }

    /**
     * Línea agregada como extensión ("el cliente quiere más horas").
     */
    public function extension(): self
    {
        return $this->state(fn (): array => ['es_extension' => true]);
    }

    public function conCantidad(string $cantidad): self
    {
        return $this->state(fn (): array => ['cantidad' => $cantidad]);
    }

    public function conTarifa(string $tarifa): self
    {
        return $this->state(fn (): array => ['tarifa_snapshot' => $tarifa]);
    }
}
