<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

/**
 * Reglas de negocio de la agenda de máquina violadas.
 */
class AgendaInvalidaException extends MaquinariaException
{
    public static function fechaPasada(string $fecha): self
    {
        return new self(
            "No se puede agendar en el pasado ({$fecha}). La agenda es el plan futuro; lo trabajado se registra como parte de trabajo."
        );
    }

    public static function horasInvalidas(string $horas): self
    {
        return new self(
            "Las horas previstas deben ser mayores a 0 y máximo 24 por día. Recibido: {$horas}."
        );
    }

    public static function enMantenimiento(string $maquina, string $fecha): self
    {
        return new self(
            "La máquina {$maquina} está en mantenimiento el {$fecha}. Finaliza el mantenimiento antes de agendarla."
        );
    }

    public static function yaAgendada(string $maquina, string $obra, string $fecha): self
    {
        return new self(
            "La máquina {$maquina} ya está agendada para {$obra} el {$fecha}. Edita ese registro en vez de duplicarlo."
        );
    }

    public static function obraNoViva(string $obra): self
    {
        return new self(
            "La obra {$obra} no está en ejecución ni pausada — no se puede agendar maquinaria a una obra que no está viva."
        );
    }

    public static function maquinaDeBaja(string $maquina): self
    {
        return new self(
            "La máquina {$maquina} está dada de baja — no se puede agendar."
        );
    }

    public static function rangoInvertido(string $desde, string $hasta): self
    {
        return new self(
            "El rango está invertido: 'Hasta' ({$hasta}) es anterior a 'Desde' ({$desde})."
        );
    }

    public static function rangoMuyLargo(int $maxDias): self
    {
        return new self(
            "El lote no puede cubrir más de {$maxDias} días. Agenda por tramos más cortos."
        );
    }
}
