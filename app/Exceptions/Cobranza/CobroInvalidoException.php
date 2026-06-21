<?php

declare(strict_types=1);

namespace App\Exceptions\Cobranza;

/**
 * Se lanza cuando un cobro a una cuenta por cobrar es inválido: monto cero o
 * negativo, o que excede el saldo pendiente (sobrecobro).
 */
final class CobroInvalidoException extends CobranzaException
{
    public static function montoNoPositivo(string $monto): self
    {
        return new self("El cobro debe ser mayor a cero. Recibido: {$monto}.");
    }

    public static function excedeSaldo(string $monto, string $saldo): self
    {
        return new self(
            "El cobro ({$monto}) excede el saldo pendiente ({$saldo}). ".
            'No se permite sobrecobro.'
        );
    }
}
