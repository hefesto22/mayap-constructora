<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Categorías de una compra (decisión Mauricio 2026-07-20): una factura
 * real puede traer de VARIAS a la vez — la cabecera guarda el CONJUNTO
 * (`compras.categorias`). Materiales y Equipo usan catálogo e inventario
 * (MAT- / HE-); Taller y Oficina son renglones a mano, gasto directo.
 *
 * Las compras sin materiales tienen dos modalidades: POR PEDIDO
 * (Registrar → Por recibir, con fecha estimada y campanita) o del MISMO
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
     * ¿Esta categoría usa catálogo con inventario? (MAT- o HE-).
     */
    public function usaCatalogo(): bool
    {
        return $this === self::Materiales || $this === self::EquipoConstruccion;
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
