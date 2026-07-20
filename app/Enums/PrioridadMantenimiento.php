<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Prioridad de reparación de un mantenimiento (decisión Mauricio
 * 2026-07-20): gerencia o recepción marcan cuál máquina es la MÁS
 * importante de reparar — el taller ataca primero las urgentes.
 *
 * Cambiarla avisa por campanita y queda en la bitácora del evento.
 */
enum PrioridadMantenimiento: string implements HasColor, HasIcon, HasLabel
{
    case Urgente = 'urgente';
    case Alta = 'alta';
    case Normal = 'normal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Urgente => 'URGENTE',
            self::Alta    => 'Alta',
            self::Normal  => 'Normal',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Urgente => 'danger',
            self::Alta    => 'warning',
            self::Normal  => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Urgente => 'heroicon-o-fire',
            self::Alta    => 'heroicon-o-arrow-trending-up',
            self::Normal  => 'heroicon-o-minus',
        };
    }

    /**
     * Orden para listar: urgente primero.
     */
    public function orden(): int
    {
        return match ($this) {
            self::Urgente => 1,
            self::Alta    => 2,
            self::Normal  => 3,
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
