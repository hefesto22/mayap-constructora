<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de una cuenta por pagar (deriva del saldo).
 *
 * - Pendiente: no se ha abonado nada (saldo = monto original).
 * - Parcial:   se abonó algo pero queda saldo.
 * - Pagada:    saldo en cero.
 */
enum EstadoCuentaPorPagar: string implements HasColor, HasIcon, HasLabel
{
    case Pendiente = 'pendiente';
    case Parcial = 'parcial';
    case Pagada = 'pagada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Parcial   => 'Abono parcial',
            self::Pagada    => 'Pagada',
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
