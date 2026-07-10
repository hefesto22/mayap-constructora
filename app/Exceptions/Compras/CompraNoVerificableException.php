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

    public static function sinLineasCapturadas(string $codigo): self
    {
        return new self("No se capturó ninguna cantidad recibida para la compra {$codigo}.");
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
