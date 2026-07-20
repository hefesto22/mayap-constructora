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
 * El orden es el camino típico pero NO es obligatorio: un taller puede
 * saltar de diagnóstico directo a reparación si había repuestos. La fase
 * "Finalizado" no existe aquí — la cubre EstadoMantenimiento.
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
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $caso): array => [$caso->value => $caso->getLabel()])
            ->all();
    }
}
