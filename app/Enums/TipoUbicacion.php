<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de ubicación de stock en el inventario.
 *
 * El inventario vive en dos clases de ubicación (ADR-0002 §1):
 *  - Bodega: bodega física (almacén central, bodega de zona).
 *  - Obra:   un proyecto, que actúa como mini-bodega de obra.
 *
 * En la tabla `existencias` y `movimientos_inventario` cada ubicación se
 * persiste en su propia columna FK (bodega_id / proyecto_id). Este enum
 * + el value object Ubicacion encapsulan esa dualidad para que el resto
 * del código no repita la lógica "¿es bodega o es proyecto?".
 */
enum TipoUbicacion: string implements HasLabel
{
    case Bodega = 'bodega';
    case Obra = 'obra';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bodega => 'Bodega',
            self::Obra   => 'Obra',
        };
    }
}
