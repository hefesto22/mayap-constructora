<?php

declare(strict_types=1);

namespace App\Services\Fichas;

use App\Enums\CategoriaItem;

/**
 * Detalle de cálculo de una línea individual — para mostrar en el form
 * y en el PDF cada renglón con su rendimiento efectivo, PU y subtotal.
 *
 * Distingue líneas tipo `item` (con rendimiento + desperdicio) de líneas
 * tipo `porcentaje` (con base y % aplicado). En el reporte/PDF se
 * muestran en columnas distintas.
 */
final readonly class DetalleLineaCalculada
{
    public function __construct(
        public int $lineaId,
        public CategoriaItem $seccion,
        public string $descripcion,
        public string $unidad,
        public string $rendimientoEfectivo,
        public string $precioUnitario,
        public string $subtotal,
        public bool $esPorcentaje = false,
        public ?string $porcentajeAplicado = null,
        public ?string $baseDelPorcentaje = null,
    ) {}
}
