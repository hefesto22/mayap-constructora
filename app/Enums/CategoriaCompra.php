<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Categoría de una compra (decisión Mauricio 2026-07-20): separa las
 * compras de materiales de construcción (el flujo original, con catálogo
 * e inventario) de las compras LIBRES — repuestos de taller, equipo y
 * oficina — que no tienen catálogo: sus líneas se escriben a mano y son
 * gasto directo, sin tocar inventario ni presupuesto de obra.
 *
 * Las compras libres tienen dos modalidades: POR PEDIDO (Registrar →
 * Por recibir, con fecha estimada de llegada y campanita) o del MISMO
 * DÍA (Confirmar recibida de una vez).
 */
enum CategoriaCompra: string implements HasColor, HasIcon, HasLabel
{
    case Materiales = 'materiales';
    case Taller = 'taller';
    case EquipoConstruccion = 'equipo_construccion';
    case Oficina = 'oficina';

    public function getLabel(): string
    {
        return match ($this) {
            self::Materiales         => 'Materiales de construcción',
            self::Taller             => 'Taller / repuestos',
            self::EquipoConstruccion => 'Equipo y construcción',
            self::Oficina            => 'Oficina',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Materiales         => 'gray',
            self::Taller             => 'warning',
            self::EquipoConstruccion => 'info',
            self::Oficina            => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Materiales         => 'heroicon-o-cube',
            self::Taller             => 'heroicon-o-wrench-screwdriver',
            self::EquipoConstruccion => 'heroicon-o-truck',
            self::Oficina            => 'heroicon-o-building-office',
        };
    }

    /**
     * ¿Compra libre? (sin catálogo: líneas a mano, sin inventario).
     */
    public function esLibre(): bool
    {
        return $this !== self::Materiales;
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
