<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * ¿Dónde se repara la máquina que se averió? (Mauricio, 2026-09-05).
 *
 * "No tiene lógica mandarla al taller si se puede solucionar en el mismo
 * lugar." La primera pregunta ante una avería NO es qué hacer con la
 * agenda: es si la máquina se mueve o no. De eso depende TODO lo demás.
 *
 *  - EN LA OBRA: la máquina no se mueve. Lo que hace falta es que le
 *    lleven algo — un repuesto, una herramienta, un mecánico — así que
 *    eso se anota y maquinaria lo despacha. La agenda queda en pie y la
 *    obra conserva la máquina.
 *  - AL TALLER: la máquina sale. Recién ahí tiene sentido preguntar si
 *    mandan otra en su lugar.
 */
enum LugarReparacion: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case EnObra = 'en_obra';

    case Taller = 'taller';

    public function getLabel(): string
    {
        return match ($this) {
            self::EnObra => 'Se repara aquí mismo',
            self::Taller => 'Tiene que ir al taller',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::EnObra => 'La máquina no se mueve. Anota qué se necesita y maquinaria lo manda a la obra.',
            self::Taller => 'La máquina sale de la obra. Hay que decidir con qué se cubren los días comprometidos.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EnObra => 'warning',
            self::Taller => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::EnObra => 'heroicon-o-wrench',
            self::Taller => 'heroicon-o-truck',
        };
    }

    /** ¿La máquina se queda donde está? */
    public function enSitio(): bool
    {
        return $this === self::EnObra;
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
