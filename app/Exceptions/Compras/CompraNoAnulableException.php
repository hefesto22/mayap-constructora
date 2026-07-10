<?php

declare(strict_types=1);

namespace App\Exceptions\Compras;

use App\Enums\EstadoCompra;

/**
 * Razones por las que una compra confirmada NO puede anularse. Cada
 * factory produce un mensaje accionable: qué bloqueó y cuál es el camino
 * correcto (nota de crédito, ajuste, etc.).
 */
final class CompraNoAnulableException extends CompraException
{
    public static function estadoInvalido(string $codigo, EstadoCompra $estado): self
    {
        return new self(
            "La compra {$codigo} no se puede anular: está en estado {$estado->getLabel()}. "
            .'Solo se anulan compras Confirmadas o Por recibir (un borrador simplemente se elimina).'
        );
    }

    public static function motivoRequerido(string $codigo): self
    {
        return new self("Anular la compra {$codigo} requiere un motivo escrito (queda en la bitácora).");
    }

    public static function stockYaUsado(string $codigo, string $detalle): self
    {
        return new self(
            "La compra {$codigo} no se puede anular: parte del material que metió ya se usó o despachó. "
            ."Para corregir lo restante usa una devolución a proveedor o un ajuste. Detalle: {$detalle}"
        );
    }

    public static function cuentaConAbonos(string $codigo): self
    {
        return new self(
            "La compra {$codigo} no se puede anular: su cuenta por pagar ya tiene abonos registrados "
            .'(hay dinero pagado al proveedor). Gestiona primero la devolución del dinero o una nota de crédito.'
        );
    }

    public static function requisicionAvanzada(string $codigo, string $codigoRequisicion): self
    {
        return new self(
            "La compra {$codigo} no se puede anular: la requisición {$codigoRequisicion} que despachó "
            .'ya avanzó (en tránsito, recibida o cerrada) — el material ya se movió físicamente.'
        );
    }
}
