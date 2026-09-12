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

    // ─── Contenedores móviles (pipa, cisterna, camioneta) ──────────

    /**
     * Se marcó el regreso de un contenedor que no es tal.
     */
    public static function bodegaNoEsContenedor(string $bodega): self
    {
        return new self(
            "{$bodega} no es un contenedor móvil: no viaja, así que no tiene ".
            'salida ni regreso que marcar.'
        );
    }

    /**
     * Un contenedor sin material declarado no sabe qué está entregando.
     */
    public static function contenedorSinMaterial(string $bodega): self
    {
        return new self(
            "{$bodega} no tiene declarado qué carga. Sin eso no se puede saber ".
            'qué entregó en la obra: edítala y elegí su material.'
        );
    }

    /**
     * Regresó con MÁS de lo que llevaba. O alguien la rellenó en el
     * camino —y eso hay que registrarlo como entrada— o el número está mal.
     */
    public static function regresoConMasDeLoQueLlevaba(string $bodega, string $regreso, string $llevaba): self
    {
        return new self(
            "{$bodega} no puede regresar con {$regreso}: salió con {$llevaba}. ".
            'Si la rellenaron en el camino, registrá primero esa entrada.'
        );
    }

    public static function nivelNegativo(string $nivel): self
    {
        return new self("Un contenedor no puede traer {$nivel}: el mínimo es cero.");
    }

    /**
     * Pasar la carga de un camión a otro camión no es devolverla: sigue
     * rodando. La devolución termina en una bodega que no se mueve.
     */
    public static function devolucionAContenedor(string $destino): self
    {
        return new self(
            "{$destino} también es un contenedor que viaja: devolver la carga ahí ".
            'la dejaría rodando igual. Elegí una bodega fija.'
        );
    }

    /**
     * Se marcó el regreso con carga de un contenedor que viene vacío.
     */
    public static function contenedorSinCarga(string $contenedor): self
    {
        return new self("{$contenedor} no trae nada encima: no hay qué devolver.");
    }
}
