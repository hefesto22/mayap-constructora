<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de reporte mensual de control (decisión Mauricio 2026-07-20):
 * la misma pantalla "Reportes fiscales" guarda dos archivos por mes:
 *
 * - Facturas: todas las compras del mes con las fotos de sus facturas.
 * - Pagos:    todos los abonos a proveedores del mes con las fotos de
 *             sus comprobantes de transferencia, y las compras que se
 *             terminaron de pagar ese mes con su historial de abonos.
 *
 * Ambos comparten el ciclo: se generan solos el día 1 y sus fotos se
 * purgan del servidor 7 días después (el PDF queda como respaldo).
 */
enum TipoReporteFiscal: string implements HasLabel
{
    case Facturas = 'facturas';
    case Pagos = 'pagos';

    public function getLabel(): string
    {
        return match ($this) {
            self::Facturas => 'Facturas de compras',
            self::Pagos    => 'Pagos a proveedores',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Facturas => 'gray',
            self::Pagos    => 'info',
        };
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
