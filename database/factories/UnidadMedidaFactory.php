<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnidadMedida>
 */
class UnidadMedidaFactory extends Factory
{
    protected $model = UnidadMedida::class;

    /**
     * Contador por proceso — misma razón que en ZonaFactory: pasar
     * Str::random a mayúsculas colapsa el espacio de caracteres y
     * `unidades_medida_codigo_unique` termina colisionando de vez en
     * cuando. Una secuencia lo vuelve imposible.
     */
    private static int $secuencia = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $codigo = 'UM'.str_pad((string) (++self::$secuencia), 4, '0', STR_PAD_LEFT);

        return [
            'codigo'  => $codigo,
            'nombre'  => $this->faker->words(2, true),
            'simbolo' => null,
            'activo'  => true,
        ];
    }

    public function inactiva(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
