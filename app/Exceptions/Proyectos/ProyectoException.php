<?php

declare(strict_types=1);

namespace App\Exceptions\Proyectos;

use RuntimeException;

/**
 * Clase base para todas las excepciones de dominio del módulo Proyectos.
 *
 * Permite atrapar cualquier error de negocio del módulo con un único
 * `catch (ProyectoException $e)` cuando es necesario, sin perder la
 * granularidad de las subclases tipadas.
 */
abstract class ProyectoException extends RuntimeException {}
