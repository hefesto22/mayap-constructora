<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

/**
 * Resumen financiero y operativo de una máquina — el número que el dueño
 * quiere ver: ¿esta máquina GANA o CUESTA?
 *
 *  - ingresos:    Σ costo de los partes de trabajo (lo que se cobra a las
 *                 obras por hora trabajada, a la tarifa pactada).
 *  - combustible: Σ costo de los consumos registrados.
 *  - utilidad:    ingresos − combustible (el mantenimiento hoy no registra
 *                 costo monetario — solo días fuera de servicio).
 *
 * Strings decimales (bcmath, escala 2) — nunca float en dinero.
 */
final readonly class ResumenMaquina
{
    public function __construct(
        public string $horas,
        public string $ingresos,
        public string $combustible,
        public string $litros,
        public string $utilidad,
        public string $margen, // % sobre ingresos ('0.00' si no hay ingresos)
        public int $totalAsignaciones,
        public int $totalMantenimientos,
    ) {}

    public function conUtilidadPositiva(): bool
    {
        return bccomp($this->utilidad, '0', 2) >= 0;
    }
}
