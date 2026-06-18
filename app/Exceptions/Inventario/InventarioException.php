<?php

declare(strict_types=1);

namespace App\Exceptions\Inventario;

use App\Domain\Exceptions\GrupoOlympoException;

/**
 * Clase base para todas las excepciones de dominio del módulo Inventario.
 *
 * Hereda de GrupoOlympoException (raíz del dominio, §7.7) para que se
 * pueda atrapar cualquier error de negocio de inventario con un único
 * `catch (InventarioException $e)` sin perder la granularidad de las
 * subclases tipadas (StockInsuficiente, MovimientoInvalido).
 */
abstract class InventarioException extends GrupoOlympoException {}
