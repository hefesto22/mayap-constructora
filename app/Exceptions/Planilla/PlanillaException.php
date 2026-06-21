<?php

declare(strict_types=1);

namespace App\Exceptions\Planilla;

use App\Domain\Exceptions\GrupoOlympoException;

/**
 * Clase base para las excepciones de dominio del módulo Planilla.
 */
abstract class PlanillaException extends GrupoOlympoException {}
