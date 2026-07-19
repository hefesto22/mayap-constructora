<?php

declare(strict_types=1);

namespace App\Exceptions\Compras;

use App\Enums\EstadoCompra;

/**
 * Se lanza cuando se intenta confirmar una compra que no está en borrador
 * o que no tiene líneas que procesar.
 */
final class CompraNoConfirmableException extends CompraException
{
    public static function estadoInvalido(string $codigo, EstadoCompra $estado): self
    {
        return new self(
            "La compra {$codigo} no se puede confirmar: su estado es ".
            "'{$estado->getLabel()}'. Solo las compras en borrador se confirman."
        );
    }

    public static function sinLineas(string $codigo): self
    {
        return new self("La compra {$codigo} no tiene líneas que confirmar.");
    }

    public static function descuentoExcedeValor(string $codigo): self
    {
        return new self(
            "La compra {$codigo} no se puede confirmar: el descuento prorrateado ".
            'deja alguna línea con valor negativo. Revisá el descuento global.'
        );
    }

    public static function consumoInmediatoABodega(string $codigoCompra, string $material): self
    {
        return new self(
            "La compra {$codigoCompra} incluye '{$material}', que es de consumo inmediato ".
            '(no almacenable, ej: agua de pipa). Ese material se compra con entrega '.
            'DIRECTA A OBRA, no a bodega.'
        );
    }

    public static function obraNoRecibeMaterial(string $codigoCompra, string $obra, string $estadoObra): self
    {
        return new self(
            "La compra {$codigoCompra} envía material a la obra '{$obra}', que está ".
            "en estado {$estadoObra}. Solo las obras EN EJECUCIÓN o PAUSADAS reciben ".
            'material — a una obra terminada, cancelada o sin iniciar no se le imputa costo.'
        );
    }

    public static function materialNoPresupuestado(string $codigoCompra, string $material, string $obra): self
    {
        return new self(
            "La compra {$codigoCompra} envía '{$material}' a la obra '{$obra}', pero ese ".
            'material NO está en el presupuesto de la obra (sus fichas no lo contemplan). '.
            'Un imprevisto legítimo lo autoriza quien tenga el permiso "Comprar fuera de '.
            'presupuesto" (pantalla de Roles).'
        );
    }

    public static function sinDocumentoFiscal(string $codigo): self
    {
        return new self(
            "La compra {$codigo} no se puede confirmar sin declarar qué documento ".
            'fiscal emitió el proveedor: factura, recibo por honorarios, boleta de '.
            "compra o ninguno. Se captura en la pestaña 'Datos de la compra'."
        );
    }

    public static function facturaSinNumero(string $codigo): self
    {
        return new self(
            "La compra {$codigo} declara FACTURA como documento fiscal pero no tiene ".
            'número de factura. Captura el correlativo del documento para poder confirmar.'
        );
    }

    public static function requisicionDeOtraObra(string $codigoCompra, string $codigoRequisicion): self
    {
        return new self(
            "La compra {$codigoCompra} está enlazada a la requisición {$codigoRequisicion}, ".
            'pero la obra destino de la compra no coincide con la obra de la requisición. '.
            'El costo se imputaría al proyecto equivocado.'
        );
    }
}
