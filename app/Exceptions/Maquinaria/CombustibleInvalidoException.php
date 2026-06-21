<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

/**
 * Se lanza cuando un consumo de combustible es inválido: la asignación no
 * está activa o la cantidad de litros no es positiva.
 */
final class CombustibleInvalidoException extends MaquinariaException
{
    public static function asignacionNoActiva(string $codigo): self
    {
        return new self(
            "La asignación {$codigo} no está activa; no se puede registrar combustible."
        );
    }

    public static function cantidadInvalida(string $litros): self
    {
        return new self(
            "La cantidad de combustible debe ser mayor a cero. Recibido: {$litros} litros."
        );
    }
}
