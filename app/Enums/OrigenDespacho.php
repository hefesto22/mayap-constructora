<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * ¿Por dónde llegó el material a la obra? (decisión Mauricio 2026-08-07)
 *
 * Es lo que diferencia las dos vías del final del flujo, y la razón por la
 * que existe: una compra directa a obra NUNCA pasa por "En tránsito" — el
 * material no viajó desde ninguna bodega nuestra, lo dejó el proveedor en
 * el sitio.
 *
 *  - Bodega        → salió de una bodega: Despachada → EnTransito → Recibida.
 *  - CompraDirecta → lo entregó el proveedor en la obra: Despachada → Recibida.
 *
 * NULL (sin origen) = todavía no se ha despachado. La máquina de estados
 * trata "sin origen" como vía bodega, que es el camino conservador: nunca
 * se salta un tránsito que sí existió.
 *
 * MIXTA: si una requisición se cubre en parte desde bodega y en parte por
 * compra, manda `Bodega` — algo sí viaja y ese tramo hay que registrarlo.
 */
enum OrigenDespacho: string implements HasColor, HasIcon, HasLabel
{
    case Bodega = 'bodega';
    case CompraDirecta = 'compra_directa';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bodega        => 'Despachada desde bodega',
            self::CompraDirecta => 'Entregada por el proveedor',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bodega        => 'info',
            self::CompraDirecta => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Bodega        => 'heroicon-o-building-storefront',
            self::CompraDirecta => 'heroicon-o-shopping-cart',
        };
    }

    /**
     * ¿El material lo entregó el proveedor directo en la obra?
     */
    public function esCompraDirecta(): bool
    {
        return $this === self::CompraDirecta;
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
