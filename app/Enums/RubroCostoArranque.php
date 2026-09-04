<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rubros del gasto que una obra heredada ya arrastraba antes del sistema.
 *
 * Son EXACTAMENTE los tres que CostoProyectoService reporta (materiales,
 * mano de obra, maquinaria): cada partida suma a su rubro para que el
 * desglose del reporte de costos siga cuadrando.
 */
enum RubroCostoArranque: string
{
    case Materiales = 'materiales';

    case ManoObra = 'mano_obra';

    case Maquinaria = 'maquinaria';

    public function getLabel(): string
    {
        return match ($this) {
            self::Materiales => 'Materiales',
            self::ManoObra   => 'Mano de obra',
            self::Maquinaria => 'Maquinaria',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Materiales => 'info',
            self::ManoObra   => 'warning',
            self::Maquinaria => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Materiales => 'heroicon-o-cube',
            self::ManoObra   => 'heroicon-o-users',
            self::Maquinaria => 'heroicon-o-truck',
        };
    }

    /** Columna del proyecto donde vive el cache de este rubro. */
    public function columnaCache(): string
    {
        return match ($this) {
            self::Materiales => 'costo_arranque_materiales',
            self::ManoObra   => 'costo_arranque_mano_obra',
            self::Maquinaria => 'costo_arranque_maquinaria',
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
}
