<?php

declare(strict_types=1);

namespace App\Exceptions\Planilla;

use App\Enums\EstadoPlanilla;

/**
 * Se lanza al intentar cerrar o modificar una planilla que no está en
 * borrador, o cerrar una sin líneas.
 */
final class PlanillaNoEditableException extends PlanillaException
{
    public static function noEsBorrador(string $codigo, EstadoPlanilla $estado): self
    {
        return new self(
            "La planilla {$codigo} no se puede cerrar: su estado es {$estado->getLabel()}. ".
            'Solo se cierran planillas en borrador.'
        );
    }

    public static function sinLineas(string $codigo): self
    {
        return new self("La planilla {$codigo} no tiene líneas; no se puede cerrar.");
    }
}
