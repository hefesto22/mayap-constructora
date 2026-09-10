<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Qué se decidió con CADA material pedido (Mauricio, 2026-09-10).
 *
 * "Si el de obra pide x materiales el bodeguero confirma si hay en bodega
 * y salen de ahí; en caso de que no haya, si se marca que se compraron
 * significa que le llegarán; en caso de que no haya y no hayan podido
 * comprar, se marca que ese no le llegará. Listo, rápido, fácil y
 * eficiente."
 *
 * Antes la decisión era del PEDIDO COMPLETO: o salía todo de bodega, o
 * TODO el pedido se iba a "requisición de compra" porque faltaba un
 * material. La obra se quedaba esperando cemento que sí había, por culpa
 * de unos clavos que no. Acá la decisión baja al renglón, que es donde
 * de verdad se toma.
 *
 * Las tres son la respuesta a la ÚNICA pregunta que importa del otro
 * lado: ¿esto me va a llegar o no?
 */
enum ResolucionLinea: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    /** Hay en bodega: sale de ahí hoy mismo. */
    case Bodega = 'bodega';

    /** No había, pero se compró: le va a llegar. */
    case Comprar = 'comprar';

    /** No había y no se consiguió: ese no le llega. */
    case NoDisponible = 'no_disponible';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bodega       => 'Sale de bodega',
            self::Comprar      => 'Se compra',
            self::NoDisponible => 'No se consiguió',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Bodega       => 'Hay existencia: se despacha hoy',
            self::Comprar      => 'Le va a llegar',
            self::NoDisponible => 'Ese no le llega',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bodega       => 'success',
            self::Comprar      => 'warning',
            self::NoDisponible => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Bodega       => 'heroicon-o-truck',
            self::Comprar      => 'heroicon-o-shopping-cart',
            self::NoDisponible => 'heroicon-o-x-circle',
        };
    }

    /**
     * ¿Esta resolución deja algo pendiente de llegar a la obra?
     * "No se consiguió" cierra el renglón; las otras dos no.
     */
    public function sigueEsperando(): bool
    {
        return $this === self::Comprar;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $acc, self $caso): array => $acc + [$caso->value => $caso->getLabel()],
            [],
        );
    }

    /**
     * @return array<string, string>
     */
    public static function colores(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $acc, self $caso): array => $acc + [$caso->value => $caso->getColor()],
            [],
        );
    }

    /**
     * @return array<string, string>
     */
    public static function iconos(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $acc, self $caso): array => $acc + [$caso->value => $caso->getIcon()],
            [],
        );
    }
}
