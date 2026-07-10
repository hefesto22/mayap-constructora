<?php

declare(strict_types=1);

namespace App\Exceptions\Compras;

use App\Enums\EstadoCompra;

/**
 * Razones por las que la corrección de un conteo de recepción falla.
 */
final class CompraNoCorregibleException extends CompraException
{
    public static function estadoInvalido(string $codigo, EstadoCompra $estado): self
    {
        return new self(
            "La compra {$codigo} no admite corrección: su estado es {$estado->getLabel()}. "
            .'Solo se corrigen conteos de compras Por recibir o Confirmadas.'
        );
    }

    public static function sinLineasCapturadas(string $codigo): self
    {
        return new self("No se capturó ninguna cantidad corregida para la compra {$codigo}.");
    }

    public static function motivoObligatorio(): self
    {
        return new self('La corrección de un conteo exige el motivo — queda en el rastro del ajuste.');
    }

    public static function lineaAjena(string $codigo, int $lineaId): self
    {
        return new self("La línea #{$lineaId} no pertenece a la compra {$codigo}.");
    }

    public static function lineaSinVerificar(string $codigo, string $material): self
    {
        return new self(
            "La línea de {$material} de la compra {$codigo} aún no fue verificada — "
            .'no hay conteo que corregir: verifícala primero.'
        );
    }

    public static function sinPermiso(string $codigo): self
    {
        return new self(
            "No puedes corregir conteos de la compra {$codigo}: la compra ya está confirmada "
            .'y corregirla mueve inventario — requiere el permiso "Corregir recepción" y alcance sobre el destino.'
        );
    }

    public static function cantidadInvalida(string $cantidad): self
    {
        return new self("Cantidad corregida inválida: {$cantidad}. Debe ser un número mayor o igual a cero.");
    }

    public static function ventanaVencida(string $codigo, int $horas): self
    {
        return new self(
            "El conteo de la compra {$codigo} quedó firme: cuadró y pasaron las {$horas} horas "
            .'de la ventana de corrección. La compra está lista para completarse.'
        );
    }

    public static function stockYaUsado(string $codigo, string $detalle): self
    {
        return new self(
            "No se puede bajar el conteo de la compra {$codigo}: parte de ese stock ya se usó. {$detalle}"
        );
    }
}
