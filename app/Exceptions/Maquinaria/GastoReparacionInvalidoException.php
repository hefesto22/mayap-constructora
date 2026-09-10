<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

/**
 * Se lanza cuando el gasto de una reparación no se puede registrar: sale
 * de bodega pero no dice qué material ni cuánto, o se quiere cargar a la
 * obra sin decir en qué concepto.
 */
final class GastoReparacionInvalidoException extends MaquinariaException
{
    public static function faltaElMaterial(): self
    {
        return new self(
            'El repuesto salió de bodega, así que hay que decir CUÁL material, de qué bodega y qué cantidad. '.
            'Sin eso no se puede bajar la existencia ni saber cuánto costó.'
        );
    }

    public static function sinConceptoDeCargo(): self
    {
        return new self(
            'Para cargarle este gasto a la obra hay que decir en qué concepto: '.
            'como gasto que ella absorbe, o como cobro que se le factura.'
        );
    }
}
