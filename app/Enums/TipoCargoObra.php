<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Cuando recepción decide cargarle una reparación a la obra, ¿en qué
 * concepto? (Mauricio, 2026-09-05).
 *
 *  - GASTO: la obra lo absorbe. Entra a su costo de maquinaria y le baja
 *    el margen. Es el caso de una obra propia.
 *  - COBRO: se le factura a quien renta. No es costo de la obra, es
 *    dinero que entra — el costo de la reparación sigue siendo de la
 *    máquina.
 *
 * El costo real NUNCA cambia: cobrar más no abarata la reparación.
 */
enum TipoCargoObra: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Gasto = 'gasto';

    case Cobro = 'cobro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gasto => 'Gasto de la obra',
            self::Cobro => 'Se le cobra a la obra',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Gasto => 'La obra lo absorbe: entra a su costo de maquinaria y le baja el margen.',
            self::Cobro => 'Se le factura a quien renta. El costo de la reparación sigue siendo de la máquina.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Gasto => 'warning',
            self::Cobro => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Gasto => 'heroicon-o-arrow-trending-down',
            self::Cobro => 'heroicon-o-banknotes',
        };
    }

    /** ¿Este cargo entra al COSTO del proyecto? */
    public function esCosto(): bool
    {
        return $this === self::Gasto;
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

    /** @return array<string, string> */
    public static function descripciones(): array
    {
        $descripciones = [];

        foreach (self::cases() as $case) {
            $descripciones[$case->value] = $case->getDescription();
        }

        return $descripciones;
    }
}
