<?php

declare(strict_types=1);

namespace App\Exceptions\Requisiciones;

use App\Domain\Exceptions\GrupoOlympoException;

/**
 * Clase base para las excepciones de dominio del módulo Requisiciones.
 *
 * Hereda de GrupoOlympoException (§7.7) para poder atrapar cualquier error
 * de negocio del flujo de requisiciones con un único catch, sin perder la
 * granularidad de las subclases tipadas.
 */
abstract class RequisicionException extends GrupoOlympoException {}
