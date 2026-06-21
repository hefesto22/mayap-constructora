<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

use App\Domain\Exceptions\GrupoOlympoException;

/**
 * Clase base para las excepciones de dominio del módulo Maquinaria.
 */
abstract class MaquinariaException extends GrupoOlympoException {}
