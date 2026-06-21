<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de una cuenta por cobrar (deriva del saldo). Espejo de
 * EstadoCuentaPorPagar pero del lado de ingresos.
 *
 * - Pendiente: no se ha cobrado nada (saldo = monto original).
 * - Parcial:   se cobró algo pero queda saldo.
 * - Pagada:    saldo en cero (cobrada por completo).
 */
enum EstadoCuentaPorCobrar: string implements HasColor, HasIcon, HasLabel
{
    case Pendiente = 'pendiente';
    case Parcial = 'parcial';
    case Pagada = 'pagada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Parcial   => 'Cobro parcial',
            self::Pagada    => 'Cobrada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente => 'danger',
            self::Parcial   => 'warning',
            self::Pagada    => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pendiente => 'heroicon-o-exclamation-circle',
            self::Parcial   => 'heroicon-o-clock',
            self::Pagada    => 'heroicon-o-check-badge',
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
