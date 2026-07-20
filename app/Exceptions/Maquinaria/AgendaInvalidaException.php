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

    public static function llegadaAntesDeTiempo(string $fecha): self
    {
        return new self(
            "Esa máquina está agendada para el {$fecha} — la llegada se confirma ese día, no antes."
        );
    }

    public static function llegadaYaConfirmada(string $cuando): self
    {
        return new self(
            "La llegada ya fue confirmada ({$cuando}) — no hace falta confirmarla dos veces."
        );
    }

    public static function confirmaSoloLaObra(): self
    {
        return new self(
            'Solo el encargado de ESA obra (o maquinaria/gerencia) puede confirmar la llegada.'
        );
    }

    public static function sigueTrabajandoEnOtraObra(string $maquina, string $obra, string $llego): self
    {
        return new self(
            "La máquina {$maquina} sigue trabajando en {$obra} (llegó {$llego}) — esa obra debe confirmar primero que terminó ahí."
        );
    }

    public static function salidaSinLlegada(): self
    {
        return new self(
            'No se puede confirmar la salida de una máquina que nunca llegó — confirma primero la llegada.'
        );
    }

    public static function salidaYaConfirmada(string $cuando): self
    {
        return new self(
            "La salida ya fue confirmada ({$cuando}) — no hace falta confirmarla dos veces."
        );
    }

    public static function noLlegoEnFuturo(string $fecha): self
    {
        return new self(
            "Esa máquina está agendada para el {$fecha} — todavía puede llegar. \"No llegó\" se marca cuando la fecha ya pasó."
        );
    }

    public static function noLlegoConLlegada(string $cuando): self
    {
        return new self(
            "La llegada de esa máquina SÍ fue confirmada ({$cuando}) — no se puede marcar como que no llegó."
        );
    }

    public static function noLlegoYaMarcado(string $cuando): self
    {
        return new self(
            "Esa agenda ya quedó marcada como no llegada ({$cuando}) — no hace falta marcarla dos veces."
        );
    }

    public static function noLlegoSinMotivo(): self
    {
        return new self(
            'Para marcar que la máquina no llegó hay que anotar el motivo — es la constancia de la contingencia.'
        );
    }
}
