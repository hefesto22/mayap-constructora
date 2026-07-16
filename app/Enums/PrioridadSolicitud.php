<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Prioridad de una solicitud de maquinaria.
 *
 *  - Normal:  el flujo estándar.
 *  - Urgente: "sí o sí se necesita" — la campanita al rol maquinaria la
 *    marca URGENTE para que reorganice el orden si la máquina ya estaba
 *    comprometida en otra cosa.
 */
enum PrioridadSolicitud: string implements HasColor, HasIcon, HasLabel
{
    case Normal = 'normal';
    case Urgente = 'urgente';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normal  => 'Normal',
            self::Urgente => 'Urgente',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Normal  => 'gray',
            self::Urgente => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Normal  => 'heroicon-o-minus-small',
            self::Urgente => 'heroicon-o-exclamation-triangle',
        };
    }

    public function esUrgente(): bool
    {
        return $this === self::Urgente;
    }
}
