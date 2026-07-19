<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Condición de pago de un proveedor o de un cliente.
 *
 * - Contado: se paga al recibir. No genera saldo en cuentas por pagar.
 * - Credito: se paga a N días. Genera saldo y cuenta por pagar.
 *
 * Los CHECK constraints de las tablas que la usan validan el conjunto.
 */
enum CondicionPago: string implements HasColor, HasIcon, HasLabel
{
    case Contado = 'contado';
    case Credito = 'credito';

    public function getLabel(): string
    {
        return match ($this) {
            self::Contado => 'Contado',
            self::Credito => 'Crédito',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Contado => 'success',
            self::Credito => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Contado => 'heroicon-o-banknotes',
            self::Credito => 'heroicon-o-credit-card',
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
