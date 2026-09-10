<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Quién metió el gasto de una reparación (Mauricio, 2026-09-05).
 *
 *  - Encargado: lo compró en la obra y lo anotó. Le falta el respaldo
 *    fiscal, así que a recepción le queda PENDIENTE conciliarlo.
 *  - Recepción: lo registró quien compra, con su factura. Nace conciliado.
 *  - Bodega: no se compró nada — el repuesto ya estaba en existencia. El
 *    monto no se escribe: sale del costo promedio del inventario, y la
 *    salida baja el stock de verdad.
 */
enum OrigenGastoReparacion: string implements HasColor, HasIcon, HasLabel
{
    case Encargado = 'encargado';

    case Recepcion = 'recepcion';

    case Bodega = 'bodega';

    public function getLabel(): string
    {
        return match ($this) {
            self::Encargado => 'Lo compró la obra',
            self::Recepcion => 'Lo compró recepción',
            self::Bodega    => 'Salió de bodega',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Encargado => 'warning',
            self::Recepcion => 'success',
            self::Bodega    => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Encargado => 'heroicon-o-hand-raised',
            self::Recepcion => 'heroicon-o-building-storefront',
            self::Bodega    => 'heroicon-o-archive-box',
        };
    }

    /** ¿Salió del inventario en vez de comprarse? */
    public function esDeBodega(): bool
    {
        return $this === self::Bodega;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $opciones = [];

        foreach (self::cases() as $case) {
            $opciones[$case->value] = $case->getLabel();
        }

        return $opciones;
    }
}
