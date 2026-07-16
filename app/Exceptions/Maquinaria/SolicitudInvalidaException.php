<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

/**
 * Reglas de negocio de las solicitudes de maquinaria violadas.
 */
class SolicitudInvalidaException extends MaquinariaException
{
    public static function yaResuelta(string $codigo, string $estado): self
    {
        return new self(
            "La solicitud {$codigo} ya fue resuelta ({$estado}) — el historial no se modifica. Crea una solicitud nueva si hace falta."
        );
    }

    public static function yaAgendadaEnLaObra(string $maquina, string $obra, string $fechas): self
    {
        return new self(
            "La máquina {$maquina} ya está agendada a {$obra} el {$fechas} — no se llama dos veces a la misma obra el mismo día. Edita ese agendado si lo que cambia es la hora."
        );
    }
}
