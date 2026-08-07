<?php

declare(strict_types=1);

namespace App\Exceptions\Requisiciones;

/**
 * Se lanza ante datos de negocio inválidos al operar una requisición:
 *  - autorizar más cantidad de la solicitada,
 *  - cantidades negativas,
 *  - una requisición sin líneas.
 *
 * Fail fast (§7.3) con mensaje accionable en español antes de tocar el
 * inventario o cambiar el estado.
 */
final class RequisicionInvalidaException extends RequisicionException
{
    public static function autorizadaExcedeSolicitada(string $autorizada, string $solicitada): self
    {
        return new self(
            "No se puede autorizar {$autorizada}: excede lo solicitado ({$solicitada}). ".
            'La autorización puede ser igual o menor, nunca mayor.'
        );
    }

    public static function cantidadNegativa(string $cantidad): self
    {
        return new self("La cantidad no puede ser negativa. Recibido: {$cantidad}.");
    }

    public static function sinLineas(string $codigo): self
    {
        return new self("La requisición {$codigo} no tiene líneas que procesar.");
    }

    public static function vencidaSinReprogramar(string $codigo, string $fechaNecesaria): self
    {
        return new self(
            "La requisición {$codigo} no se puede autorizar: su fecha necesaria ".
            "({$fechaNecesaria}) ya venció. Reprogramá la fecha (con motivo) o rechazala."
        );
    }

    public static function soloSolicitadaSeReprograma(string $codigo, string $estado): self
    {
        return new self(
            "La requisición {$codigo} no se puede reprogramar: está en estado ".
            "\"{$estado}\" y solo una requisición Solicitada admite mover su fecha necesaria."
        );
    }

    public static function fechaReprogramadaEnPasado(string $fecha): self
    {
        return new self(
            "No se puede reprogramar hacia {$fecha}: la nueva fecha necesaria ".
            'debe ser hoy o una fecha futura.'
        );
    }

    public static function motivoReprogramacionRequerido(): self
    {
        return new self(
            'Reprogramar la fecha necesaria requiere un motivo: queda en la '.
            'bitácora de la requisición.'
        );
    }

    /**
     * El bug de REQ-2026-00005: el material lo entregó el proveedor en la
     * obra y aun así alguien la marcó "en tránsito".
     */
    public static function transitoEnDespachoDirecto(string $codigo): self
    {
        return new self(
            "La requisición {$codigo} no puede marcarse en tránsito: el material lo ".
            'entregó el proveedor directo en la obra, no salió de ninguna bodega. '.
            'Lo que falta es que la obra confirme la recepción.'
        );
    }
}
