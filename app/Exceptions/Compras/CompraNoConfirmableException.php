<?php

declare(strict_types=1);

namespace App\Exceptions\Compras;

use App\Enums\EstadoCompra;

/**
 * Se lanza cuando se intenta confirmar una compra que no está en borrador
 * o que no tiene líneas que procesar.
 */
final class CompraNoConfirmableException extends CompraException
{
    public static function estadoInvalido(string $codigo, EstadoCompra $estado): self
    {
        return new self(
            "La compra {$codigo} no se puede confirmar: su estado es ".
            "'{$estado->getLabel()}'. Solo las compras en borrador se confirman."
        );
    }

    public static function sinLineas(string $codigo): self
    {
        return new self("La compra {$codigo} no tiene líneas que confirmar.");
    }
}
