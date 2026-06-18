<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\TipoMovimientoInventario;

/**
 * Resultado de registrar un movimiento de inventario — Value Object inmutable.
 *
 * Expone el id del movimiento creado, el costo unitario que se aplicó, y
 * el saldo resultante de las ubicaciones afectadas (origen y/o destino,
 * según el tipo). Permite al llamador (Resource, Job, test) verificar el
 * efecto sin re-consultar la DB.
 */
final readonly class ResultadoMovimiento
{
    public function __construct(
        public int $movimientoId,
        public TipoMovimientoInventario $tipo,
        public string $costoUnitarioAplicado,
        public ?SaldoUbicacion $origen,
        public ?SaldoUbicacion $destino,
    ) {}
}
