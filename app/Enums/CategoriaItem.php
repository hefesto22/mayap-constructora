<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Categorías de items en la base de precios de una constructora.
 *
 * Estas 4 categorías son canónicas en la industria de construcción
 * hondureña y reflejan cómo el maestro de obras estructura un APU
 * (Análisis de Precio Unitario):
 *
 * - Materiales: insumos físicos consumidos (cemento, varilla, arena).
 * - Mano de obra: jornadas, horas-hombre, especialidades (albañil, fontanero).
 * - Herramienta y equipo: herramienta menor, alquiler de equipo,
 *   horas-máquina (mezcladora, vibrador, retroexcavadora).
 * - Indirectos: transporte, supervisión, papelería, prorrateo.
 *
 * Implementa los contratos de Filament para que las columnas y selects
 * muestren label, color y ícono sin código boilerplate en cada Resource.
 */
enum CategoriaItem: string implements HasColor, HasIcon, HasLabel
{
    case Materiales = 'materiales';
    case ManoObra = 'mano_obra';
    case HerramientaEquipo = 'herramienta_equipo';
    case Indirectos = 'indirectos';

    public function getLabel(): string
    {
        return match ($this) {
            self::Materiales        => 'Materiales',
            self::ManoObra          => 'Mano de obra',
            self::HerramientaEquipo => 'Herramienta y equipo',
            self::Indirectos        => 'Indirectos',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Materiales        => 'info',     // azul
            self::ManoObra          => 'success',  // verde
            self::HerramientaEquipo => 'warning',  // naranja
            self::Indirectos        => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Materiales        => 'heroicon-o-cube',
            self::ManoObra          => 'heroicon-o-users',
            self::HerramientaEquipo => 'heroicon-o-wrench-screwdriver',
            self::Indirectos        => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }

    /**
     * Lista plana para selects/filtros: ['materiales' => 'Materiales', ...].
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $caso): array => [$caso->value => $caso->getLabel()])
            ->all();
    }
}
