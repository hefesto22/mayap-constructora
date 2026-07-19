<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Documento fiscal que emitió el proveedor por una compra (decisión
 * Mauricio 2026-07-19): factura, recibo por honorarios, boleta de
 * compra o ninguno. Se exige al CONFIRMAR la compra — en borrador puede
 * quedar vacío porque a veces el documento llega después.
 *
 * Si es FACTURA, el número es obligatorio (campo numero_factura). El
 * ISV es independiente: hay boletas sin ISV y compras sin documento
 * que sí lo pagaron — eso lo decide quien captura.
 *
 * A propósito NO se captura CAI por compra (cientos de facturas al mes
 * lo harían insufrible); si algún día se quiere, va UNA vez por
 * proveedor con su fecha límite de emisión.
 */
enum TipoDocumentoFiscal: string implements HasColor, HasIcon, HasLabel
{
    case Factura = 'factura';
    case ReciboHonorarios = 'recibo_honorarios';
    case BoletaCompra = 'boleta_compra';
    case Ninguno = 'ninguno';

    public function getLabel(): string
    {
        return match ($this) {
            self::Factura          => 'Factura',
            self::ReciboHonorarios => 'Recibo por honorarios',
            self::BoletaCompra     => 'Boleta de compra',
            self::Ninguno          => 'Ninguno',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Factura          => 'success',
            self::ReciboHonorarios => 'info',
            self::BoletaCompra     => 'warning',
            self::Ninguno          => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Factura          => 'heroicon-o-document-text',
            self::ReciboHonorarios => 'heroicon-o-pencil-square',
            self::BoletaCompra     => 'heroicon-o-receipt-percent',
            self::Ninguno          => 'heroicon-o-no-symbol',
        };
    }

    /**
     * ¿Este documento exige número? Solo la factura — recibos y boletas
     * a veces ni traen correlativo.
     */
    public function exigeNumero(): bool
    {
        return $this === self::Factura;
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
