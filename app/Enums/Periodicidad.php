<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Frecuencia con que se corre una planilla.
 *
 * El CHECK constraint de la tabla `planillas` valida el conjunto.
 */
enum Periodicidad: string implements HasLabel
{
    case Semanal = 'semanal';
    case Quincenal = 'quincenal';
    case Mensual = 'mensual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Semanal   => 'Semanal',
            self::Quincenal => 'Quincenal',
            self::Mensual   => 'Mensual',
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
