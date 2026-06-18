<?php

declare(strict_types=1);

namespace App\Exceptions\Inventario;

/**
 * Se lanza cuando un movimiento se intenta registrar con datos que
 * violan las reglas del dominio antes de tocar la DB:
 *  - cantidad cero o negativa,
 *  - costo negativo,
 *  - ajuste o merma sin motivo (requerido para trazabilidad),
 *  - origen y destino iguales en un traslado,
 *  - falta de origen/destino según el tipo de movimiento.
 *
 * Es defensa en profundidad sobre los CHECK constraints de la tabla:
 * falla temprano con un mensaje accionable en español en vez de dejar
 * que reviente el driver de Postgres con un error críptico.
 */
final class MovimientoInvalidoException extends InventarioException
{
    public static function cantidadInvalida(string $cantidad): self
    {
        return new self("La cantidad del movimiento debe ser mayor a cero. Recibido: {$cantidad}.");
    }

    public static function costoNegativo(string $costo): self
    {
        return new self("El costo unitario no puede ser negativo. Recibido: {$costo}.");
    }

    public static function motivoRequerido(string $tipo): self
    {
        return new self("El movimiento '{$tipo}' requiere un motivo escrito para trazabilidad.");
    }

    public static function mismaUbicacion(string $ubicacion): self
    {
        return new self("El origen y el destino de un traslado no pueden ser la misma ubicación ({$ubicacion}).");
    }
}
