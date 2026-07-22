<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

use App\Enums\EstadoMaquina;
use App\Enums\FaseMantenimiento;

/**
 * Se lanza cuando una operación de mantenimiento es inválida: la máquina no
 * está operativa, no hay obra de la cual sustituir, el mantenimiento ya no
 * está en proceso, el avance de fase viene incompleto, o la fase
 * intenta retroceder (las fases solo avanzan).
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

    public static function noSePuedeAvanzar(string $codigo): self
    {
        return new self(
            "El mantenimiento {$codigo} ya está finalizado; no se pueden registrar más avances."
        );
    }

    public static function faltaFechaEstimada(string $codigo): self
    {
        return new self(
            "La fase de compra de repuestos del mantenimiento {$codigo} necesita ".
            'la fecha estimada de recepción de los repuestos.'
        );
    }

    public static function faltaSustituta(string $codigo): self
    {
        return new self(
            "Para transferir la agenda de la máquina {$codigo} hay que elegir la máquina sustituta."
        );
    }

    public static function retrocesoDeFase(string $codigo, FaseMantenimiento $actual, FaseMantenimiento $pedida): self
    {
        return new self(
            "El mantenimiento {$codigo} ya está en \"{$actual->getLabel()}\": ".
            "no puede regresar a \"{$pedida->getLabel()}\" — las fases solo avanzan."
        );
    }
}
