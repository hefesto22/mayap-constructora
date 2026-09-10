<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Cierre del día de una máquina en obra: ¿qué pasa con ella al terminar?
 *
 * Existe para responder "¿dónde está cada máquina?" sin tener que
 * adivinarlo (decisión Mauricio 2026-09-05). Al final de cada día el
 * encargado registra el horómetro y elige una de estas cuatro:
 *
 *  - SIGUE EN LA OBRA (el caso normal, y por eso el que viene marcado):
 *    cierra el DÍA pero NO la estadía. La máquina amanece ahí mañana y
 *    el calendario la sigue pintando en esa obra.
 *  - Las otras tres CIERRAN la estadía: la máquina queda libre para que
 *    otra obra la reciba, y este dato dice dónde buscarla.
 */
enum DestinoSalidaMaquina: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case SigueEnObra = 'sigue_en_obra';

    case Bodega = 'bodega';

    case OtraObra = 'otra_obra';

    case Taller = 'taller';

    /**
     * ¿Este destino termina la estadía en la obra? "Se quedó" NO: cierra
     * el día y deja a la máquina donde está — es la única opción que no
     * libera la máquina para otra obra.
     */
    public function cierraEstadia(): bool
    {
        return $this !== self::SigueEnObra;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::SigueEnObra => 'Se quedó en la obra',
            self::Bodega      => 'Volvió a bodega',
            self::OtraObra    => 'Salió a otra obra',
            self::Taller      => 'Se fue al taller',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SigueEnObra => 'Terminó el día y amanece en la misma obra: la estadía sigue abierta y mañana se cierra el día aquí mismo.',
            self::Bodega      => 'Terminó y volvió al patio: queda libre y maquinaria ya la puede agendar a otra obra.',
            self::OtraObra    => 'Terminó y salió directo a otra obra: queda libre y la obra que la recibe confirma su llegada.',
            self::Taller      => 'Terminó y se fue al taller: queda fuera de servicio hasta que la reparación se cierre.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SigueEnObra => 'primary',
            self::Bodega      => 'success',
            self::OtraObra    => 'info',
            self::Taller      => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::SigueEnObra => 'heroicon-o-map-pin',
            self::Bodega      => 'heroicon-o-home-modern',
            self::OtraObra    => 'heroicon-o-arrow-right-circle',
            self::Taller      => 'heroicon-o-wrench-screwdriver',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $opciones = [];

        foreach (self::cases() as $case) {
            $opciones[$case->value] = $case->getLabel();
        }

        return $opciones;
    }

    /** @return array<string, string> */
    public static function descripciones(): array
    {
        $descripciones = [];

        foreach (self::cases() as $case) {
            $descripciones[$case->value] = $case->getDescription();
        }

        return $descripciones;
    }
}
