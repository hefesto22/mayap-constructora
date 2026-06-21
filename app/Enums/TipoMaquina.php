<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de maquinaria pesada de la constructora. Es una clasificación
 * para reportes y filtros; no cambia el comportamiento del cobro por horas.
 *
 * El CHECK constraint de la tabla `maquinas` valida el conjunto.
 */
enum TipoMaquina: string implements HasLabel
{
    case Excavadora = 'excavadora';
    case Retroexcavadora = 'retroexcavadora';
    case Cargadora = 'cargadora';
    case Volqueta = 'volqueta';
    case Motoniveladora = 'motoniveladora';
    case Compactadora = 'compactadora';
    case Bulldozer = 'bulldozer';
    case Grua = 'grua';
    case Otro = 'otro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Excavadora      => 'Excavadora',
            self::Retroexcavadora => 'Retroexcavadora',
            self::Cargadora       => 'Cargadora',
            self::Volqueta        => 'Volqueta',
            self::Motoniveladora  => 'Motoniveladora',
            self::Compactadora    => 'Compactadora',
            self::Bulldozer       => 'Bulldózer',
            self::Grua            => 'Grúa',
            self::Otro            => 'Otro',
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
