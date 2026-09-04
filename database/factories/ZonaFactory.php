<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Zona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zona>
 */
class ZonaFactory extends Factory
{
    protected $model = Zona::class;

    /**
     * Contador por proceso para códigos únicos.
     *
     * El código anterior era strtoupper(Str::random(3)): al pasar a
     * mayúsculas las minúsculas colapsan con las mayúsculas y el espacio
     * real cae a ~46k combinaciones. Con varias zonas por test el problema
     * del cumpleaños hace colisionar `zonas_codigo_unique` cada tantas
     * corridas — un flaky que aparece y desaparece sin que nadie toque
     * nada (pasó el 2026-09-03 en FichaTest). Una secuencia no colisiona
     * nunca, y el prefijo ZN evita chocar con los códigos que los tests
     * fijan a mano (SRC, TGU, ...).
     */
    private static int $secuencia = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo'      => 'ZN'.str_pad((string) (++self::$secuencia), 4, '0', STR_PAD_LEFT),
            'nombre'      => $this->faker->city(),
            'descripcion' => null,
            'activa'      => true,
        ];
    }

    public function inactiva(): self
    {
        return $this->state(fn (): array => ['activa' => false]);
    }
}
