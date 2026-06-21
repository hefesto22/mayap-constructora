<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

use App\Enums\EstadoMaquina;

/**
 * Se lanza cuando una operación de mantenimiento es inválida: la máquina no
 * está operativa, no hay obra de la cual sustituir, o el mantenimiento ya no
 * está en proceso.
 */
final class MantenimientoInvalidoException extends MaquinariaException
{
    public static function maquinaNoOperativa(string $codigo, EstadoMaquina $estado): self
    {
        return new self(
            "La máquina {$codigo} no se puede enviar a mantenimiento. ".
            "Estado actual: {$estado->getLabel()}."
        );
    }

    public static function sinObraParaSustituir(string $codigo): self
    {
        return new self(
            "La máquina {$codigo} no tiene una asignación activa, así que no hay ".
            'obra de la cual sustituirla. Asigna la máquina sustituta directamente.'
        );
    }

    public static function mantenimientoNoEnProceso(string $codigo): self
    {
        return new self(
            "El mantenimiento {$codigo} no está en proceso; no se puede finalizar de nuevo."
        );
    }
}
