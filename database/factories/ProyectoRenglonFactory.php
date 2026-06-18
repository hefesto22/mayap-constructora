<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProyectoRenglon>
 */
class ProyectoRenglonFactory extends Factory
{
    protected $model = ProyectoRenglon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cantidad = number_format($this->faker->randomFloat(4, 1, 100), 4, '.', '');
        $precio = number_format($this->faker->randomFloat(2, 50, 5000), 2, '.', '');
        $subtotal = self::calcularSubtotal($cantidad, $precio);

        return [
            'proyecto_id'              => Proyecto::factory(),
            'ficha_id'                 => Ficha::factory(),
            'orden'                    => 0,
            'capitulo'                 => null,
            'cantidad'                 => $cantidad,
            'precio_unitario_snapshot' => $precio,
            'subtotal_cache'           => $subtotal,
            'notas'                    => null,
        ];
    }

    public function paraProyecto(Proyecto $proyecto): self
    {
        return $this->state(fn (): array => ['proyecto_id' => $proyecto->id]);
    }

    public function conFicha(Ficha $ficha): self
    {
        return $this->state(function () use ($ficha): array {
            $cantidad = number_format($this->faker->randomFloat(4, 1, 100), 4, '.', '');
            $precio = (string) $ficha->precio_venta_cache;
            $subtotal = self::calcularSubtotal($cantidad, $precio);

            return [
                'ficha_id'                 => $ficha->id,
                'cantidad'                 => $cantidad,
                'precio_unitario_snapshot' => $precio,
                'subtotal_cache'           => $subtotal,
            ];
        });
    }

    /**
     * Define cantidad + precio explícitos. Recalcula subtotal_cache con
     * bcmath (half-up a 2 decimales) para satisfacer el CHECK constraint
     * de coherencia y evitar errores de redondeo float.
     */
    public function conCantidad(float|string $cantidad, float|string $precio): self
    {
        $cantidadStr = (string) $cantidad;
        $precioStr = (string) $precio;

        return $this->state(fn (): array => [
            'cantidad'                 => $cantidadStr,
            'precio_unitario_snapshot' => $precioStr,
            'subtotal_cache'           => self::calcularSubtotal($cantidadStr, $precioStr),
        ]);
    }

    /**
     * cantidad × precio con bcmath y redondeo half-away-from-zero a 2
     * decimales. Coincide con el SCALE_FINAL del calculador del dominio.
     */
    private static function calcularSubtotal(string $cantidad, string $precio): string
    {
        $crudo = bcmul($cantidad, $precio, 4);
        $factor = '0.005';

        return bccomp($crudo, '0', 4) >= 0
            ? bcadd($crudo, $factor, 2)
            : bcsub($crudo, $factor, 2);
    }

    public function enCapitulo(string $capitulo): self
    {
        return $this->state(fn (): array => ['capitulo' => $capitulo]);
    }
}
