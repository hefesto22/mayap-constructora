<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado operativo de una máquina (ciclo de vida).
 *
 * - Disponible:    libre, lista para asignarse a una obra.
 * - Asignada:      trabajando en una obra (no se puede reasignar sin liberar).
 * - Mantenimiento: fuera de servicio por reparación (gatilla sustitución).
 * - Baja:          retirada definitivamente (estado terminal).
 *
 * El CHECK constraint de la tabla `maquinas` valida el conjunto.
 */
enum EstadoMaquina: string implements HasColor, HasIcon, HasLabel
{
    case Disponible = 'disponible';
    case Asignada = 'asignada';
    case Mantenimiento = 'mantenimiento';
    case Baja = 'baja';

    public function getLabel(): string
    {
        return match ($this) {
            self::Disponible    => 'Disponible',
            self::Asignada      => 'Asignada',
            self::Mantenimiento => 'En mantenimiento',
            self::Baja          => 'De baja',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Disponible    => 'success',
            self::Asignada      => 'primary',
            self::Mantenimiento => 'warning',
            self::Baja          => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Disponible    => 'heroicon-o-check-circle',
            self::Asignada      => 'heroicon-o-wrench',
            self::Mantenimiento => 'heroicon-o-wrench-screwdriver',
            self::Baja          => 'heroicon-o-x-circle',
        };
    }

    /**
     * Estados a los que se puede transicionar desde el actual.
     *
     * @return array<int, self>
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::Disponible    => [self::Asignada, self::Mantenimiento, self::Baja],
            self::Asignada      => [self::Disponible, self::Mantenimiento],
            self::Mantenimiento => [self::Disponible, self::Baja],
            self::Baja          => [],
        };
    }

    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesPermitidas(), strict: true);
    }

    public function esTerminal(): bool
    {
        return $this === self::Baja;
    }

    /**
     * Una máquina puede asignarse a una obra solo si está disponible.
     */
    public function puedeAsignarse(): bool
    {
        return $this === self::Disponible;
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
