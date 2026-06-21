<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

/**
 * Se lanza cuando un parte de trabajo es inválido: la asignación no está
 * activa, el horómetro retrocede, faltan horas, o hay horas extra sin motivo.
 */
final class ParteInvalidoException extends MaquinariaException
{
    public static function asignacionNoActiva(string $codigo): self
    {
        return new self(
            "La asignación {$codigo} no está activa; no se pueden registrar partes de trabajo."
        );
    }

    public static function horasInvalidas(string $horas): self
    {
        return new self(
            "Las horas trabajadas deben ser mayores a cero. Recibido: {$horas}."
        );
    }

    public static function lecturaRetrocede(string $lecturaFinal, string $horometroActual): self
    {
        return new self(
            "El horómetro no puede retroceder. Lectura final ({$lecturaFinal}) ".
            "es menor al horómetro actual de la máquina ({$horometroActual})."
        );
    }

    public static function lecturaFinalMenorQueInicial(string $lecturaFinal, string $lecturaInicial): self
    {
        return new self(
            "La lectura final ({$lecturaFinal}) no puede ser menor a la inicial ({$lecturaInicial})."
        );
    }

    public static function sinMotivoHorasExtra(string $horasExtra): self
    {
        return new self(
            "Se registraron {$horasExtra} horas extra. Debes indicar el motivo de las horas extra."
        );
    }
}
