<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de una solicitud de maquinaria.
 *
 *  - Pendiente: la agenda no pudo (taller o duplicado) — el rol
 *    maquinaria la resuelve: agendarla en otra fecha/máquina o rechazar.
 *  - Agendada:  ya tiene su agendado en el calendario (automático si la
 *    máquina estaba libre, o resuelto por maquinaria).
 *  - Rechazada: no se dará — con motivo para el solicitante.
 *
 * El CHECK constraint de `solicitudes_maquina` valida el conjunto.
 */
enum EstadoSolicitudMaquina: string implements HasColor, HasIcon, HasLabel
{
    case Pendiente = 'pendiente';
    case Agendada = 'agendada';
    case Rechazada = 'rechazada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Agendada  => 'Agendada',
            self::Rechazada => 'Rechazada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::Agendada  => 'success',
            self::Rechazada => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pendiente => 'heroicon-o-clock',
            self::Agendada  => 'heroicon-o-calendar-days',
            self::Rechazada => 'heroicon-o-x-circle',
        };
    }

    /**
     * Solo las pendientes se pueden resolver — agendada/rechazada son
     * terminales (historial inmutable del proyecto).
     */
    public function esPendiente(): bool
    {
        return $this === self::Pendiente;
    }
}
