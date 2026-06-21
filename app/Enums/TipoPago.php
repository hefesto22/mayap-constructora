<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Forma en que se le paga a un empleado.
 *
 * - Jornal:  paga por día trabajado. La tarifa es el pago por día; el monto
 *            de la planilla = días trabajados × tarifa.
 * - Salario: sueldo fijo por período (semanal/quincenal/mensual). La tarifa
 *            es el monto del período; el monto de planilla es ese fijo.
 * - Destajo: paga por tarea/trabajo terminado. La tarifa base no aplica; el
 *            monto se captura por tarea en cada planilla.
 *
 * El CHECK constraint de la tabla `empleados` valida el conjunto.
 */
enum TipoPago: string implements HasColor, HasIcon, HasLabel
{
    case Jornal = 'jornal';
    case Salario = 'salario';
    case Destajo = 'destajo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Jornal  => 'Por jornal (día)',
            self::Salario => 'Salario fijo',
            self::Destajo => 'Por tarea (destajo)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Jornal  => 'info',
            self::Salario => 'success',
            self::Destajo => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Jornal  => 'heroicon-o-calendar-days',
            self::Salario => 'heroicon-o-banknotes',
            self::Destajo => 'heroicon-o-squares-2x2',
        };
    }

    /**
     * El monto se deriva de días × tarifa (jornal) o es la tarifa fija
     * (salario). El destajo se captura a mano por tarea.
     */
    public function usaDiasTrabajados(): bool
    {
        return $this === self::Jornal;
    }

    public function tarifaEsFija(): bool
    {
        return $this === self::Salario;
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
