<?php

declare(strict_types=1);

namespace App\Exceptions\Proyectos;

use App\Enums\EstadoProyecto;

/**
 * Errores de datos al operar la fase de ejecución de un proyecto:
 * motivos obligatorios faltantes, plazos o anticipos inválidos, o
 * registrar anticipo en un estado que no lo admite.
 *
 * Factory methods nombrados para construir cada caso con un mensaje
 * accionable en español.
 */
final class DatosEjecucionInvalidosException extends ProyectoException
{
    public static function motivoRequerido(string $accion): self
    {
        return new self(
            "Debes indicar un motivo para {$accion} el proyecto."
        );
    }

    public static function plazoInvalido(int $dias): self
    {
        return new self(
            "El plazo de ejecución debe ser de al menos 1 día. Recibido: {$dias}."
        );
    }

    public static function anticipoInvalido(string $monto): self
    {
        return new self(
            "El monto del anticipo debe ser mayor que cero. Recibido: L {$monto}."
        );
    }

    public static function estadoNoPermiteAnticipo(EstadoProyecto $estado): self
    {
        return new self(
            'No se puede registrar anticipo con el proyecto en estado '.
            "'{$estado->getLabel()}'. Solo se permite desde Aprobada o durante la ejecución."
        );
    }

    public static function estadoNoPermiteAjuste(EstadoProyecto $estado): self
    {
        return new self(
            'No se puede ajustar el plazo con el proyecto en estado '.
            "'{$estado->getLabel()}'. Solo se permite mientras está En ejecución o Pausada."
        );
    }
}
