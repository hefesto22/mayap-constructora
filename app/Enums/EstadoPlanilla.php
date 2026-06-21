<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de una planilla.
 *
 * - Borrador: se está armando; se editan líneas. No cuenta en el costo de obra.
 * - Cerrada:  pago confirmado; las líneas cuentan en el costo de la obra.
 *
 * El CHECK constraint de la tabla `planillas` valida el conjunto.
 */
enum EstadoPlanilla: string implements HasColor, HasIcon, HasLabel
{
    case Borrador = 'borrador';
    case Cerrada = 'cerrada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Cerrada  => 'Cerrada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Cerrada  => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Borrador => 'heroicon-o-pencil-square',
            self::Cerrada  => 'heroicon-o-lock-closed',
        };
    }

    public function permiteEditar(): bool
    {
        return $this === self::Borrador;
    }

    public function esCerrada(): bool
    {
        return $this === self::Cerrada;
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
