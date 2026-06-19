<?php

declare(strict_types=1);

namespace App\Exceptions\Compras;

use App\Domain\Exceptions\GrupoOlympoException;

/**
 * Clase base para las excepciones de dominio del módulo Compras.
 */
abstract class CompraException extends GrupoOlympoException {}
