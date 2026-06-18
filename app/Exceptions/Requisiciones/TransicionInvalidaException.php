<?php

declare(strict_types=1);

namespace App\Exceptions\Requisiciones;

use App\Enums\EstadoRequisicion;

/**
 * Se lanza cuando se intenta avanzar una requisición a un estado que su
 * estado actual no permite (ver EstadoRequisicion::transicionesPermitidas).
 *
 * Defensa de la máquina de estados: ningún Resource ni comando puede forzar
 * un salto inválido (ej: de Solicitada directo a Despachada sin autorizar).
 */
final class TransicionInvalidaException extends RequisicionException
{
    public function __construct(
        public readonly string $codigo,
        public readonly EstadoRequisicion $estadoActual,
        public readonly EstadoRequisicion $estadoDestino,
    ) {
        parent::__construct(
            "La requisición {$codigo} no puede pasar de ".
            "'{$estadoActual->getLabel()}' a '{$estadoDestino->getLabel()}'."
        );
    }
}
