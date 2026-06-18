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
}
