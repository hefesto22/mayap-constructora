<?php

declare(strict_types=1);

namespace App\Exceptions\Proyectos;

use App\Enums\EstadoProyecto;

/**
 * Errores de negocio al operar un proyecto de renta de maquinaria:
 * operar un proyecto que no es renta, aprobar sin líneas, extender
 * en un estado que no lo admite, o ajustar una cuenta inexistente.
 *
 * Factory methods nombrados con mensaje accionable en español.
 */
final class RentaInvalidaException extends ProyectoException
{
    public static function noEsRenta(string $codigo): self
    {
        return new self(
            "El proyecto {$codigo} no es una renta de maquinaria. ".
            'Esta operación solo aplica a proyectos tipo Renta.'
        );
    }

    public static function sinLineas(string $codigo): self
    {
        return new self(
            "La renta {$codigo} no tiene líneas. Agregá al menos una ".
            'máquina con sus horas o días antes de aprobar.'
        );
    }

    public static function estadoNoPermiteExtender(EstadoProyecto $estado): self
    {
        return new self(
            'No se puede extender la renta con el proyecto en estado '.
            "'{$estado->getLabel()}'. Solo se permite desde Aprobada o durante la ejecución."
        );
    }

    public static function estadoNoPermiteAgregarLineas(EstadoProyecto $estado): self
    {
        return new self(
            'No se pueden agregar líneas con el proyecto en estado '.
            "'{$estado->getLabel()}'. En Borrador se edita libre; después ".
            'de aprobar, usá la acción "Extender renta".'
        );
    }

    public static function sinCuentaPorCobrar(string $codigo): self
    {
        return new self(
            "La renta {$codigo} no tiene cuenta por cobrar asociada. ".
            'Se genera al aprobar el proyecto — revisá la bitácora.'
        );
    }
}
