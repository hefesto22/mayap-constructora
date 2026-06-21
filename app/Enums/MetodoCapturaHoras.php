<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Cómo se capturaron las horas de un parte de trabajo.
 *
 * - Horometro: lectura inicial y final del reloj de la máquina (fuente
 *              principal, la más rigurosa). El sistema valida que el reloj
 *              no retroceda y actualiza el horómetro de la máquina.
 * - Manual:    horas reportadas a mano (respaldo cuando el horómetro falla
 *              o no aplica). No toca el horómetro de la máquina.
 *
 * El CHECK constraint de la tabla `partes_trabajo` valida el conjunto y la
 * coherencia de las lecturas según el método.
 */
enum MetodoCapturaHoras: string implements HasColor, HasIcon, HasLabel
{
    case Horometro = 'horometro';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Horometro => 'Horómetro',
            self::Manual    => 'Manual',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Horometro => 'success',
            self::Manual    => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Horometro => 'heroicon-o-clock',
            self::Manual    => 'heroicon-o-pencil-square',
        };
    }

    public function usaHorometro(): bool
    {
        return $this === self::Horometro;
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
