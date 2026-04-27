<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Base de cálculo para una línea tipo `porcentaje` dentro de una ficha.
 *
 * Define sobre qué subtotal de la ficha se aplica el porcentaje:
 *
 * - Materiales: % sobre subtotal de líneas de la sección Materiales.
 * - ManoObra: % sobre subtotal de líneas de Mano de Obra.
 *   Patrón típico: HERRAMIENTA MENOR 5% sobre MO.
 * - HerramientaEquipo: % sobre subtotal de Herramienta y Equipo.
 * - CostoDirecto: % sobre la suma de las 3 categorías directas.
 *   Patrón típico: IMPREVISTOS 3% sobre costo directo.
 *
 * NO se incluye "indirectos" como base — un % sobre indirectos no tiene
 * sentido en el oficio, y permitirlo crearía dependencias circulares
 * cuando los indirectos también son porcentajes.
 *
 * Esta enum es DISTINTA a CategoriaItem porque:
 *  - Agrega CostoDirecto (no es categoría de item, es agregado).
 *  - Excluye Indirectos (no se usa como base de cálculo).
 */
enum CategoriaBaseLinea: string implements HasLabel
{
    case Materiales = 'materiales';
    case ManoObra = 'mano_obra';
    case HerramientaEquipo = 'herramienta_equipo';
    case CostoDirecto = 'costo_directo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Materiales        => 'Subtotal de materiales',
            self::ManoObra          => 'Subtotal de mano de obra',
            self::HerramientaEquipo => 'Subtotal de herramienta y equipo',
            self::CostoDirecto      => 'Costo directo (suma de las 3 categorías)',
        };
    }

    /**
     * Indica si esta base agrega múltiples categorías directas.
     *
     * Las bases "agregadas" (CostoDirecto) deben calcularse DESPUÉS
     * que las bases puntuales — el Service usa este flag para ordenar
     * las dos pasadas de cálculo.
     */
    public function esAgregada(): bool
    {
        return $this === self::CostoDirecto;
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
