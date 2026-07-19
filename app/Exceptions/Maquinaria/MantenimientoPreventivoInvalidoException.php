<?php

declare(strict_types=1);

namespace App\Exceptions\Maquinaria;

use Exception;

/**
 * Reglas del mantenimiento preventivo violadas — factory methods por
 * caso (patrón de la casa) para mensajes consistentes en UI y tests.
 */
class MantenimientoPreventivoInvalidoException extends Exception
{
    public static function fechaFutura(string $fecha): self
    {
        return new self("La fecha del cambio ({$fecha}) no puede ser futura: se registra lo ya realizado.");
    }

    public static function lecturaNegativa(string $campo, string $valor): self
    {
        return new self("La lectura de {$campo} ({$valor}) no puede ser negativa.");
    }

    public static function planInactivo(string $nombre): self
    {
        return new self("El plan {$nombre} está inactivo: actívalo antes de registrar cambios.");
    }
}
