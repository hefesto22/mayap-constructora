<?php

declare(strict_types=1);

namespace App\Exceptions\Inventario;

/**
 * Se lanza cuando un movimiento de salida (despacho, traslado, consumo,
 * ajuste negativo, devolución) intenta sacar más stock del que existe
 * en la ubicación de origen.
 *
 * Lleva el contexto necesario para diagnosticar sin abrir la DB: qué
 * item, en qué ubicación, cuánto se pidió y cuánto había. Fail fast
 * (§7.3): la operación se aborta dentro de la transacción y se revierte.
 */
final class StockInsuficienteException extends InventarioException
{
    public function __construct(
        public readonly int $itemId,
        public readonly string $ubicacion,
        public readonly string $solicitado,
        public readonly string $disponible,
    ) {
        parent::__construct(
            "Stock insuficiente del item {$itemId} en {$ubicacion}: ".
            "solicitado {$solicitado}, disponible {$disponible}."
        );
    }
}
