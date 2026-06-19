<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estados de una compra a proveedor.
 *
 * - Borrador:   editable, NO ha movido stock.
 * - Confirmada: registró las entradas de inventario (vía WAC) y, si es a
 *   crédito, generó la cuenta por pagar. Documento inmutable.
 * - Anulada:    revertida (movimientos inversos de inventario). Terminal.
 *
 * Los CHECK constraints de la tabla `compras` validan el conjunto.
 */
enum EstadoCompra: string implements HasColor, HasIcon, HasLabel
{
    case Borrador = 'borrador';
    case Confirmada = 'confirmada';
    case Anulada = 'anulada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Borrador   => 'Borrador',
            self::Confirmada => 'Confirmada',
            self::Anulada    => 'Anulada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Borrador   => 'gray',
            self::Confirmada => 'success',
            self::Anulada    => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Borrador   => 'heroicon-o-pencil-square',
            self::Confirmada => 'heroicon-o-check-badge',
            self::Anulada    => 'heroicon-o-x-circle',
        };
    }

    /**
     * ¿Permite editar las líneas (solo en borrador)?
     */
    public function permiteEditar(): bool
    {
        return $this === self::Borrador;
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
