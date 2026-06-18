<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Existencia;

/**
 * Foto inmutable del saldo de una existencia tras aplicar un movimiento.
 *
 * Todos los montos son strings (bcmath / decimal). `costoPromedio` viene
 * derivado del accessor del modelo (valor_total / cantidad), redondeado
 * a 2 decimales.
 */
final readonly class SaldoUbicacion
{
    public function __construct(
        public Ubicacion $ubicacion,
        public string $cantidad,
        public string $valorTotal,
        public string $costoPromedio,
    ) {}

    public static function desdeExistencia(Existencia $existencia, Ubicacion $ubicacion): self
    {
        return new self(
            ubicacion: $ubicacion,
            cantidad: (string) $existencia->cantidad,
            valorTotal: (string) $existencia->valor_total,
            costoPromedio: $existencia->costo_promedio,
        );
    }
}
