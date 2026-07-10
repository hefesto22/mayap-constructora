<?php

declare(strict_types=1);

namespace App\Services\Requisiciones;

/**
 * Estado presupuestario de UN material dentro de un proyecto.
 *
 * Todas las cantidades son strings decimales (bcmath, escala 4) para
 * mantener la precisión de NUMERIC en Postgres.
 *
 *  - presupuestado: lo que las fichas del proyecto dicen que se necesita
 *    (Σ cantidad_renglón × rendimiento_efectivo de líneas tipo item).
 *  - solicitado: lo comprometido en requisiciones no rechazadas
 *    (COALESCE(cantidad_autorizada, cantidad_solicitada)).
 *  - despachado: lo que ya salió de bodega hacia la obra.
 *  - disponible: presupuestado − solicitado (puede ser negativo = exceso).
 */
final readonly class PresupuestoMaterial
{
    public function __construct(
        public int $materialId,
        public string $materialCodigo,
        public string $materialNombre,
        public string $unidad,
        public string $presupuestado,
        public string $solicitado,
        public string $despachado,
    ) {}

    public function disponible(): string
    {
        return bcsub($this->presupuestado, $this->solicitado, 4);
    }

    /**
     * ¿Lo solicitado ya supera lo presupuestado?
     */
    public function excedido(): bool
    {
        return bccomp($this->solicitado, $this->presupuestado, 4) > 0;
    }

    /**
     * Exceso sobre el presupuesto ('0.0000' si no hay exceso).
     */
    public function exceso(): string
    {
        return $this->excedido()
            ? bcsub($this->solicitado, $this->presupuestado, 4)
            : '0.0000';
    }

    /**
     * % del presupuesto ya comprometido por requisiciones (escala 2).
     * Sin presupuesto pero con solicitudes → 999.99 (sobregiro total:
     * se está pidiendo material que las fichas nunca contemplaron).
     */
    public function porcentajeComprometido(): string
    {
        if (bccomp($this->presupuestado, '0', 4) <= 0) {
            return bccomp($this->solicitado, '0', 4) > 0 ? '999.99' : '0.00';
        }

        return bcmul(bcdiv($this->solicitado, $this->presupuestado, 6), '100', 2);
    }
}
