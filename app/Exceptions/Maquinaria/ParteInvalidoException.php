<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

use App\Enums\ModalidadTrabajo;

/**
 * Se lanza cuando un parte de trabajo es inválido: la asignación no está
 * activa, el horómetro retrocede, faltan horas, hay horas extra sin motivo,
 * o falta el dato de la modalidad (km, viajes, actividad).
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

    public static function kmInvalidos(?string $km): self
    {
        return new self(
            'Un parte por kilometraje necesita los kilómetros recorridos (mayores a cero).'
            .($km !== null ? " Recibido: {$km}." : '')
        );
    }

    public static function viajesInvalidos(?int $viajes): self
    {
        return new self(
            'Un parte por viajes necesita el número de viajes del día (mayor a cero).'
            .($viajes !== null ? " Recibido: {$viajes}." : '')
        );
    }

    public static function sinActividad(): self
    {
        return new self(
            'Un parte de flete necesita la actividad realizada (ej: "FLETE DE CEMENTO A LA OBRA X").'
        );
    }

    /**
     * Cobrar más horas de las que el motor estuvo encendido es facturar aire.
     */
    public static function cobraMasQueElMotor(string $horasCobradas, string $horasMotor): self
    {
        return new self(
            "No se pueden cobrar {$horasCobradas} horas si el horómetro solo corrió {$horasMotor}. ".
            'Revisá la lectura final o las horas trabajadas.'
        );
    }

    public static function sinMotivoIdle(string $porcentaje, string $horasMuertas): self
    {
        return new self(
            "El {$porcentaje}% del tiempo de motor no se cobró ({$horasMuertas} horas muertas). ".
            'Escribí el motivo: esperando material, se llovió, traslado dentro de la obra…'
        );
    }

    public static function sinTarifaPactada(ModalidadTrabajo $modalidad, string $codigoAsignacion): self
    {
        return new self(
            "La asignación {$codigoAsignacion} no tiene pactada la tarifa ".
            "{$modalidad->sufijoTarifa()}, que es como cobra esta máquina. ".
            'Editá la asignación y pactala antes de registrar el parte.'
        );
    }
}
