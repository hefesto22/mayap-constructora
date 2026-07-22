<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Fase de un mantenimiento correctivo (decisión Mauricio 2026-07-20):
 * el ciclo de vida de la reparación mientras el evento está EN PROCESO.
 *
 *   Revisión/diagnóstico → Sin repuestos → Compra de repuestos → Reparación
 *
 * Las fases solo AVANZAN (decisión Mauricio 2026-07-22): un taller puede
 * saltar de diagnóstico directo a reparación si había repuestos, pero no
 * regresar — la historia no se reescribe, para eso está la bitácora. La
 * fase "Finalizado" no existe aquí — la cubre EstadoMantenimiento.
 *
 * Cada cambio de fase deja constancia en la bitácora del mantenimiento
 * (fecha, hora, quién y detalle).
 */
enum FaseMantenimiento: string implements HasColor, HasIcon, HasLabel
{
    case Diagnostico = 'diagnostico';
    case SinRepuestos = 'sin_repuestos';
    case CompraRepuestos = 'compra_repuestos';
    case Reparacion = 'reparacion';

    public function getLabel(): string
    {
        return match ($this) {
            self::Diagnostico     => 'Revisión / diagnóstico',
            self::SinRepuestos    => 'Sin repuestos',
            self::CompraRepuestos => 'Compra de repuestos',
            self::Reparacion      => 'Reparación',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Diagnostico     => 'info',
            self::SinRepuestos    => 'danger',
            self::CompraRepuestos => 'warning',
            self::Reparacion      => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Diagnostico     => 'heroicon-o-magnifying-glass',
            self::SinRepuestos    => 'heroicon-o-exclamation-triangle',
            self::CompraRepuestos => 'heroicon-o-shopping-cart',
            self::Reparacion      => 'heroicon-o-wrench-screwdriver',
        };
    }

    /**
     * ¿En esta fase se está esperando que lleguen repuestos? (aplica la
     * fecha estimada de recepción y su campanita).
     */
    public function esperaRepuestos(): bool
    {
        return $this === self::SinRepuestos || $this === self::CompraRepuestos;
    }

    /**
     * La posición en el camino de la reparación: las fases solo avanzan.
     */
    public function orden(): int
    {
        return match ($this) {
            self::Diagnostico     => 0,
            self::SinRepuestos    => 1,
            self::CompraRepuestos => 2,
            self::Reparacion      => 3,
        };
    }

    /**
     * Las opciones válidas DESDE una fase: la actual y las que siguen
     * (quedarse es válido; regresar no).
     *
     * @return array<string, string>
     */
    public static function opcionesDesde(self $actual): array
    {
        return collect(self::cases())
            ->filter(static fn (self $caso): bool => $caso->orden() >= $actual->orden())
            ->mapWithKeys(static fn (self $caso): array => [$caso->value => $caso->getLabel()])
            ->all();
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
