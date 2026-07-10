<?php

declare(strict_types=1);

namespace App\Exceptions\Compras;

use App\Enums\EstadoCompra;

/**
 * Razones por las que el cierre definitivo (Completada) de una compra falla.
 */
final class CompraNoCompletableException extends CompraException
{
    public static function estadoInvalido(string $codigo, EstadoCompra $estado): self
    {
        return new self(
            "La compra {$codigo} no se puede completar: su estado es {$estado->getLabel()}. "
            .'Solo se completa una compra Confirmada.'
        );
    }

    public static function conDiferencias(string $codigo): self
    {
        return new self(
            "La compra {$codigo} NO cuadra (facturado ≠ recibido) — no se puede completar. "
            .'Resuelve la diferencia primero: recontar (Corregir conteo) o anular si el error fue de captura.'
        );
    }

    public static function ventanaAbierta(string $codigo, int $horas): self
    {
        return new self(
            "La compra {$codigo} cuadró hace poco: la ventana de corrección de {$horas} horas "
            .'sigue abierta. Se completa cuando venza.'
        );
    }

    public static function sinPermiso(string $codigo): self
    {
        return new self(
            "No puedes completar la compra {$codigo}: requiere el permiso \"Completar compra\" "
            .'(pestaña Personalizados de Roles).'
        );
    }
}
