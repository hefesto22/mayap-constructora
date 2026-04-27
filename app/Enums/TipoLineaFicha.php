<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Tipo discriminador de una línea dentro de una ficha APU.
 *
 * Una ficha está compuesta por N líneas. Cada línea es uno de dos tipos:
 *
 * - Item: referencia un item del catálogo de precios. Tiene rendimiento
 *   base + desperdicio %. Subtotal = rendimiento × (1 + desperdicio/100)
 *   × precio_unitario_actual_del_item.
 *
 * - Porcentaje: línea derivada que se calcula como % sobre el subtotal de
 *   alguna categoría (materiales, mano de obra, herramienta y equipo, o
 *   costo directo total). Patrón clásico hondureño: HERRAMIENTA MENOR
 *   (3-5% sobre MO), IMPREVISTOS (3-5% sobre directo), SUPERVISIÓN
 *   TÉCNICA (5-10% sobre MO).
 *
 * Los CHECK constraints a nivel DB garantizan que cada tipo SOLO tenga
 * pobladas las columnas que le corresponden.
 */
enum TipoLineaFicha: string implements HasColor, HasIcon, HasLabel
{
    case Item = 'item';
    case Porcentaje = 'porcentaje';

    public function getLabel(): string
    {
        return match ($this) {
            self::Item       => 'Item',
            self::Porcentaje => 'Porcentaje',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Item       => 'primary',
            self::Porcentaje => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Item       => 'heroicon-o-cube',
            self::Porcentaje => 'heroicon-o-calculator',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $caso): array => [$caso->value => $caso->getLabel()])
            ->all();
    }
}
