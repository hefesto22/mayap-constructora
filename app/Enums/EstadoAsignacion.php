<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de una asignación de máquina a obra.
 *
 * - Activa:     la máquina está trabajando en la obra; recibe partes de
 *               trabajo. Solo puede haber UNA activa por máquina a la vez.
 * - Finalizada: la asignación terminó (la máquina se liberó o entró a
 *               mantenimiento). Ya no recibe partes.
 *
 * El CHECK constraint de la tabla `asignaciones_maquina` valida el conjunto.
 */
enum EstadoAsignacion: string implements HasColor, HasIcon, HasLabel
{
    case Activa = 'activa';
    case Finalizada = 'finalizada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Activa     => 'Activa',
            self::Finalizada => 'Finalizada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Activa     => 'success',
            self::Finalizada => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Activa     => 'heroicon-o-play-circle',
            self::Finalizada => 'heroicon-o-stop-circle',
        };
    }

    public function esActiva(): bool
    {
        return $this === self::Activa;
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
