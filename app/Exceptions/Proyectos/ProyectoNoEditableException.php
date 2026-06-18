<?php

declare(strict_types=1);

namespace App\Exceptions\Proyectos;

use App\Enums\EstadoProyecto;

/**
 * Se lanza cuando se intenta una operación de edición de renglones
 * (agregar / quitar / modificar cantidad) sobre un proyecto que ya
 * no está en estado Borrador.
 *
 * Razón: una vez que se envía la cotización al cliente, los renglones
 * y montos se congelan. Cambiarlos podría producir discrepancias
 * legales con la cotización ya entregada.
 *
 * Para cambios después de enviada, el flujo correcto es:
 *  1. Duplicar el proyecto (estado pasa a Borrador)
 *  2. Modificar el duplicado
 *  3. Enviar nueva versión al cliente
 */
final class ProyectoNoEditableException extends ProyectoException
{
    public function __construct(
        public readonly int $proyectoId,
        public readonly string $proyectoCodigo,
        public readonly EstadoProyecto $estadoActual,
    ) {
        parent::__construct(
            "El proyecto {$proyectoCodigo} no se puede editar porque su ".
            "estado actual es '{$estadoActual->getLabel()}'. ".
            'Solo los proyectos en estado Borrador permiten editar renglones. '.
            'Para hacer cambios, duplica el proyecto y edita la copia.'
        );
    }
}
