<?php

declare(strict_types=1);

namespace App\Exceptions\Compras;

use App\Enums\EstadoCompra;

/**
 * Se lanza al intentar mover la fecha estimada de llegada de una compra
 * que no está esperando material, sin motivo, o hacia el pasado.
 *
 * La fecha de llegada es una PROMESA que la obra usa para organizarse:
 * moverla siempre deja rastro (bitácora de la requisición) y siempre avisa.
 */
final class LlegadaNoReprogramableException extends CompraException
{
    public static function estadoInvalido(string $codigo, EstadoCompra $estado): self
    {
        return new self(
            "La llegada de la compra {$codigo} no se puede reprogramar: su estado es ".
            "'{$estado->getLabel()}'. Solo un pedido 'Por recibir' sigue en camino."
        );
    }

    public static function motivoRequerido(): self
    {
        return new self(
            'Mover la fecha de llegada requiere un motivo: la obra se organizó con '.
            'la fecha anterior y el motivo queda en la bitácora de la requisición.'
        );
    }

    public static function fechaEnPasado(string $fecha): self
    {
        return new self(
            "No se puede reprogramar la llegada hacia {$fecha}: la nueva fecha debe ".
            'ser hoy o futura. Si el material ya llegó, verificá la recepción.'
        );
    }
}
