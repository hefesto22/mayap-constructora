<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Modalidad de trabajo de un parte (decisión Mauricio 2026-07-20 —
 * "así funcionan"):
 *
 * - Horas:        maquinaria pesada por horómetro (el flujo original).
 * - Kilometraje:  pick-ups por km recorridos (alimenta el kilometraje
 *                 de la máquina y su mantenimiento preventivo por km).
 * - Viajes:       volquetas por viajes con origen → destino y material.
 * - Flete:        camiones/pick-ups por actividad ("FLETE DE CEMENTO A
 *                 LA OBRA X") — texto libre, sin catálogo.
 *
 * En TODAS las modalidades el parte sigue registrando las horas del
 * día: el costo interno de la obra es horas × tarifa pactada. La
 * modalidad agrega el dato con el que además se COBRA (viajes, km).
 *
 * Cada máquina tiene su modalidad por defecto (`modalidad_trabajo`),
 * pero el parte puede cambiarla (una volqueta a veces va por horas).
 */
enum ModalidadTrabajo: string implements HasColor, HasIcon, HasLabel
{
    case Horas = 'horas';
    case Kilometraje = 'kilometraje';
    case Viajes = 'viajes';
    case Flete = 'flete';

    public function getLabel(): string
    {
        return match ($this) {
            self::Horas       => 'Horas (horómetro)',
            self::Kilometraje => 'Kilometraje',
            self::Viajes      => 'Viajes (origen → destino)',
            self::Flete       => 'Flete / actividad',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Horas       => 'primary',
            self::Kilometraje => 'info',
            self::Viajes      => 'warning',
            self::Flete       => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Horas       => 'heroicon-o-clock',
            self::Kilometraje => 'heroicon-o-map',
            self::Viajes      => 'heroicon-o-arrows-right-left',
            self::Flete       => 'heroicon-o-truck',
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
