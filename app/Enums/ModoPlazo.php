<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Modo de cómputo del plazo de ejecución de una obra.
 *
 *  - Calendario: días corridos. fecha_fin = inicio + N días.
 *  - Hábiles: excluye sábados y domingos. fecha_fin = el N-ésimo
 *    día hábil contado a partir del inicio.
 *
 * Honduras: los feriados nacionales aún NO se descuentan en modo
 * hábiles (pendiente: tabla/config de feriados). De momento solo
 * fin de semana. Documentado como deuda explícita.
 *
 * El cálculo concreto vive en App\Support\CalculadorPlazo.
 */
enum ModoPlazo: string implements HasLabel
{
    case Calendario = 'calendario';
    case Habiles = 'habiles';

    public function getLabel(): string
    {
        return match ($this) {
            self::Calendario => 'Días calendario',
            self::Habiles    => 'Días hábiles (sin fines de semana)',
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
