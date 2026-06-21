<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Nivel de consumo del presupuesto de una obra (costo real / presupuesto).
 *
 * - Sano:        consume menos del 80% del presupuesto.
 * - EnRiesgo:    consumió entre 80% y 100% — vigilar de cerca.
 * - Sobregirado: el costo ya superó el presupuesto (>100%) — pérdida de margen.
 *
 * El umbral de alerta (80%) es la señal que pidió el dueño para reaccionar
 * antes de comerse el margen.
 */
enum NivelPresupuesto: string implements HasColor, HasIcon, HasLabel
{
    case Sano = 'sano';
    case EnRiesgo = 'en_riesgo';
    case Sobregirado = 'sobregirado';

    public const float UMBRAL_RIESGO = 80.0;

    public const float UMBRAL_SOBREGIRO = 100.0;

    public function getLabel(): string
    {
        return match ($this) {
            self::Sano        => 'Sano',
            self::EnRiesgo    => 'En riesgo',
            self::Sobregirado => 'Sobregirado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sano        => 'success',
            self::EnRiesgo    => 'warning',
            self::Sobregirado => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Sano        => 'heroicon-o-check-circle',
            self::EnRiesgo    => 'heroicon-o-exclamation-triangle',
            self::Sobregirado => 'heroicon-o-fire',
        };
    }

    /**
     * Clasifica un porcentaje consumido (string numérico) en su nivel.
     */
    public static function desdePorcentaje(string $porcentaje): self
    {
        if (bccomp($porcentaje, (string) self::UMBRAL_SOBREGIRO, 2) > 0) {
            return self::Sobregirado;
        }

        if (bccomp($porcentaje, (string) self::UMBRAL_RIESGO, 2) >= 0) {
            return self::EnRiesgo;
        }

        return self::Sano;
    }
}
