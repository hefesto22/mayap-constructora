<?php

declare(strict_types=1);

namespace App\Exceptions\Compras;

use App\Enums\EstadoCompra;

/**
 * Razones por las que la verificación de recepción de una compra falla.
 */
final class CompraNoVerificableException extends CompraException
{
    public static function estadoInvalido(string $codigo, EstadoCompra $estado): self
    {
        return new self(
            "La compra {$codigo} no está en verificación: su estado es {$estado->getLabel()}. "
            .'Solo se verifica lo que está Por recibir.'
        );
    }

    /**
     * Verificar es lo que METE el stock a la obra y crea la cuenta por
     * pagar. Hacerlo antes de que el proveedor entregue metería inventario
     * fantasma y deuda por algo que no llegó (decisión Mauricio
     * 2026-08-07). Si el proveedor se adelantó, recepción reprograma la
     * llegada — queda el rastro de que la fecha se movió y por qué.
     */
    public static function llegadaEnElFuturo(string $codigo, string $fechaLlegada): self
    {
        return new self(
            "La compra {$codigo} todavía no se puede verificar: el proveedor ".
            "entrega el {$fechaLlegada}. Si el material ya llegó antes, pedile a ".
            'recepción que reprograme la llegada y luego verificás lo que trajo.'
        );
    }

    public static function sinLineasCapturadas(string $codigo): self
    {
        return new self("No se capturó ninguna cantidad recibida para la compra {$codigo}.");
    }

    public static function noEsperaCaptura(string $codigo): self
    {
        return new self(
            "La compra {$codigo} ya tiene su detalle capturado. ".
            'Para corregir lo contado usa la corrección de conteo, no la captura.'
        );
    }

    public static function noCuadraConLaFactura(string $codigo, string $declarado, string $capturado): self
    {
        return new self(
            "Lo capturado no cuadra con la factura {$codigo}: la factura dice L. {$declarado} ".
            "y lo capturado suma L. {$capturado}. ".
            'Revisa cantidades y precios — si la diferencia es real, anótala en las notas y avisa a compras.'
        );
    }

    public static function materialRepetido(string $codigo, string $material): self
    {
        return new self(
            "El material {$material} aparece dos veces en la captura de la compra {$codigo}. ".
            'Súmalo en una sola línea.'
        );
    }

    public static function lineaAjena(string $codigo, int $lineaId): self
    {
        return new self("La línea #{$lineaId} no pertenece a la compra {$codigo}.");
    }

    public static function lineaYaVerificada(string $codigo, string $material): self
    {
        return new self(
            "La línea de {$material} de la compra {$codigo} ya fue verificada — no se re-verifica."
        );
    }

    public static function sinAlcance(string $codigo, string $material): self
    {
        return new self(
            "No puedes verificar la línea de {$material} de la compra {$codigo}: "
            .'esa porción la verifica el responsable de su destino (bodeguero de esa bodega '
            .'o encargado de esa obra).'
        );
    }

    public static function cantidadInvalida(string $cantidad): self
    {
        return new self("Cantidad recibida inválida: {$cantidad}. Debe ser un número mayor o igual a cero.");
    }
}
