<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

use App\Enums\EstadoMaquina;

/**
 * Se lanza cuando una asignación de máquina a obra es inválida: la máquina
 * no está disponible, ya tiene una asignación activa, o se intenta finalizar
 * una asignación que ya no está activa.
 */
final class AsignacionInvalidaException extends MaquinariaException
{
    public static function maquinaNoDisponible(string $codigo, EstadoMaquina $estado): self
    {
        return new self(
            "La máquina {$codigo} no está disponible para asignar. ".
            "Estado actual: {$estado->getLabel()}. Solo se asignan máquinas disponibles."
        );
    }

    public static function yaTieneAsignacionActiva(string $codigo): self
    {
        return new self(
            "La máquina {$codigo} ya tiene una asignación activa. ".
            'Finalízala antes de asignarla a otra obra.'
        );
    }

    public static function asignacionNoActiva(string $codigo): self
    {
        return new self(
            "La asignación {$codigo} no está activa; no se puede finalizar de nuevo."
        );
    }
}
