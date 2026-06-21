<?php

declare(strict_types=1);

namespace App\Exceptions\Compras;

/**
 * Se lanza cuando un abono a una cuenta por pagar es inválido: monto cero o
 * negativo, o que excede el saldo pendiente (sobrepago).
 */
final class AbonoInvalidoException extends CompraException
{
    public static function montoNoPositivo(string $monto): self
    {
        return new self("El abono debe ser mayor a cero. Recibido: {$monto}.");
    }

    public static function excedeSaldo(string $monto, string $saldo): self
    {
        return new self(
            "El abono ({$monto}) excede el saldo pendiente ({$saldo}). ".
            'No se permite sobrepago.'
        );
    }
}
