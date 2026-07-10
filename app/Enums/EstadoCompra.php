<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estados de una compra a proveedor (flujo G2 — verificación de recepción):
 *
 * - Borrador:   editable, NO ha movido stock.
 * - PorRecibir: registrada por recepción; el material viene en camino. El
 *   bodeguero (porción bodega) y el encargado (porción obra) verifican lo
 *   que llegó CONTRA lo facturado. Aún NO hay stock ni CxP.
 * - Confirmada: recepción verificada — el stock entró (por lo RECIBIDO) y,
 *   si es a crédito, existe la cuenta por pagar (por lo FACTURADO).
 * - Completada: conciliada y SELLADA — todo cuadró (facturado = recibido) y
 *   pasó la ventana de corrección. No se corrige, ni anula, ni edita.
 * - Anulada:    revertida (movimientos inversos de inventario). Terminal.
 *
 * Los CHECK constraints de la tabla `compras` validan el conjunto.
 */
enum EstadoCompra: string implements HasColor, HasIcon, HasLabel
{
    case Borrador = 'borrador';
    case PorRecibir = 'por_recibir';
    case Confirmada = 'confirmada';
    case Completada = 'completada';
    case Anulada = 'anulada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Borrador   => 'Borrador',
            self::PorRecibir => 'Por recibir',
            self::Confirmada => 'Confirmada',
            self::Completada => 'Completada',
            self::Anulada    => 'Anulada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Borrador   => 'gray',
            self::PorRecibir => 'warning',
            self::Confirmada => 'success',
            self::Completada => 'info',
            self::Anulada    => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Borrador   => 'heroicon-o-pencil-square',
            self::PorRecibir => 'heroicon-o-truck',
            self::Confirmada => 'heroicon-o-check-badge',
            self::Completada => 'heroicon-o-lock-closed',
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
