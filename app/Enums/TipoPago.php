<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Forma en que se le paga a un empleado.
 *
 * - Jornal:     paga por día trabajado. La tarifa es el pago por día; el
 *               monto de la planilla = días trabajados × tarifa.
 * - Salario:    sueldo fijo por período (semanal/quincenal/mensual). La
 *               tarifa es el monto del período; el monto de planilla es ese
 *               fijo.
 * - Destajo:    paga por tarea/trabajo terminado. La tarifa base no aplica;
 *               el monto se captura por tarea en cada planilla.
 * - Honorarios: profesionales por servicios. Monto fijo del período (como
 *               salario) pero con RETENCIÓN del 12.5% (ISR sobre honorarios,
 *               Honduras) que el recibo muestra y resta del neto.
 *
 * Los CHECK constraints de `empleados` y `planilla_lineas` validan el
 * conjunto.
 */
enum TipoPago: string implements HasColor, HasIcon, HasLabel
{
    case Jornal = 'jornal';
    case Salario = 'salario';
    case Destajo = 'destajo';
    case Honorarios = 'honorarios';

    /** Retención de ley sugerida para honorarios profesionales (HN). */
    public const string RETENCION_HONORARIOS = '12.50';

    public function getLabel(): string
    {
        return match ($this) {
            self::Jornal     => 'Por jornal (día)',
            self::Salario    => 'Salario fijo',
            self::Destajo    => 'Por tarea (destajo)',
            self::Honorarios => 'Honorarios (retención 12.5%)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Jornal     => 'info',
            self::Salario    => 'success',
            self::Destajo    => 'warning',
            self::Honorarios => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Jornal     => 'heroicon-o-calendar-days',
            self::Salario    => 'heroicon-o-banknotes',
            self::Destajo    => 'heroicon-o-squares-2x2',
            self::Honorarios => 'heroicon-o-academic-cap',
        };
    }

    /**
     * El monto se deriva de días × tarifa (jornal) o es la tarifa fija
     * (salario/honorarios). El destajo se captura a mano por tarea.
     */
    public function usaDiasTrabajados(): bool
    {
        return $this === self::Jornal;
    }

    public function tarifaEsFija(): bool
    {
        return $this === self::Salario || $this === self::Honorarios;
    }

    /**
     * Retención sugerida al elegir este tipo de pago (editable en la
     * línea — "una cosa así del 12.5%", puede variar por caso).
     */
    public function retencionSugerida(): ?string
    {
        return $this === self::Honorarios ? self::RETENCION_HONORARIOS : null;
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
