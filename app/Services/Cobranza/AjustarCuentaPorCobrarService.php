<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

use App\Enums\EstadoCuentaPorCobrar;
use App\Exceptions\Cobranza\CobroInvalidoException;
use App\Models\CuentaPorCobrar;
use Illuminate\Support\Facades\DB;

/**
 * Aumenta el monto de una cuenta por cobrar existente — la puerta para
 * cargos posteriores a la emisión: horas extra de una renta ("se cobra
 * lo cotizado; si trabajó más, el extra se suma") y extensiones
 * aprobadas después de generar la cuenta.
 *
 * Sube monto_original y saldo JUNTOS en la misma transacción (el CHECK
 * saldo <= monto_original se evalúa sobre el estado final) y recalcula
 * el estado: una cuenta ya pagada que recibe un extra vuelve a Parcial.
 *
 * NUNCA disminuye: correcciones a la baja son otra historia (nota de
 * crédito) y se decidirán cuando exista el caso real.
 *
 * Todo ajuste queda en la bitácora con motivo y montos.
 */
final class AjustarCuentaPorCobrarService
{
    private const int SCALE = 2;

    public function aumentar(
        CuentaPorCobrar $cuenta,
        string $monto,
        string $motivo,
        ?int $userId = null,
    ): CuentaPorCobrar {
        if (bccomp($monto, '0', self::SCALE) <= 0) {
            throw CobroInvalidoException::montoNoPositivo($monto);
        }

        return DB::transaction(function () use ($cuenta, $monto, $motivo, $userId): CuentaPorCobrar {
            $cuentaBloqueada = CuentaPorCobrar::query()
                ->whereKey($cuenta->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $montoAnterior = (string) $cuentaBloqueada->monto_original;
            $saldoAnterior = (string) $cuentaBloqueada->saldo;

            $cuentaBloqueada->monto_original = bcadd($montoAnterior, $monto, self::SCALE);
            $cuentaBloqueada->saldo = bcadd($saldoAnterior, $monto, self::SCALE);
            $cuentaBloqueada->estado = $this->estadoSegunSaldo(
                (string) $cuentaBloqueada->saldo,
                (string) $cuentaBloqueada->monto_original,
            );
            $cuentaBloqueada->save();

            activity('cobranza')
                ->performedOn($cuentaBloqueada)
                ->causedBy($userId)
                ->withProperties([
                    'monto_aumento'  => $monto,
                    'monto_anterior' => $montoAnterior,
                    'monto_nuevo'    => (string) $cuentaBloqueada->monto_original,
                    'saldo_anterior' => $saldoAnterior,
                    'saldo_nuevo'    => (string) $cuentaBloqueada->saldo,
                    'motivo'         => $motivo,
                ])
                ->event('cuenta_aumentada')
                ->log("CxC {$cuentaBloqueada->codigo} aumentada L {$monto}: {$motivo}");

            return $cuentaBloqueada;
        });
    }

    private function estadoSegunSaldo(string $saldo, string $montoOriginal): EstadoCuentaPorCobrar
    {
        if (bccomp($saldo, '0', self::SCALE) <= 0) {
            return EstadoCuentaPorCobrar::Pagada;
        }

        if (bccomp($saldo, $montoOriginal, self::SCALE) < 0) {
            return EstadoCuentaPorCobrar::Parcial;
        }

        return EstadoCuentaPorCobrar::Pendiente;
    }
}
