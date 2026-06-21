<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de un evento de mantenimiento de una máquina.
 *
 * - EnProceso: la máquina está fuera de servicio en reparación.
 * - Finalizado: la reparación terminó; la máquina vuelve a estar disponible.
 *
 * El CHECK constraint de la tabla `mantenimientos_maquina` valida el conjunto.
 */
enum EstadoMantenimiento: string implements HasColor, HasIcon, HasLabel
{
    case EnProceso = 'en_proceso';
    case Finalizado = 'finalizado';

    public function getLabel(): string
    {
        return match ($this) {
            self::EnProceso  => 'En proceso',
            self::Finalizado => 'Finalizado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EnProceso  => 'warning',
            self::Finalizado => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::EnProceso  => 'heroicon-o-wrench-screwdriver',
            self::Finalizado => 'heroicon-o-check-badge',
        };
    }

    public function esEnProceso(): bool
    {
        return $this === self::EnProceso;
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
