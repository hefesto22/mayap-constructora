<?php

declare(strict_types=1);

namespace App\Exceptions\Proyectos;

use App\Enums\EstadoProyecto;

/**
 * Se lanza al intentar un cambio de estado que la máquina de estados
 * del proyecto no permite (ej: finalizar un proyecto en Borrador, o
 * iniciar uno que no está Aprobado).
 *
 * Las transiciones válidas las define EstadoProyecto::transicionesPermitidas().
 */
final class TransicionEstadoInvalidaException extends ProyectoException
{
    public function __construct(
        public readonly string $proyectoCodigo,
        public readonly EstadoProyecto $origen,
        public readonly EstadoProyecto $destino,
    ) {
        parent::__construct(
            "El proyecto {$proyectoCodigo} no puede pasar de '{$origen->getLabel()}' ".
            "a '{$destino->getLabel()}'. Transición no permitida por el flujo del proyecto."
        );
    }
}
