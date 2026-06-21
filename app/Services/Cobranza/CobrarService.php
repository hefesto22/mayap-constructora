<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

use App\Enums\EstadoCuentaPorCobrar;
use App\Exceptions\Cobranza\CobroInvalidoException;
use App\Models\Cobro;
use App\Models\CuentaPorCobrar;
use Illuminate\Support\Facades\DB;

/**
 * Registra cobros contra una cuenta por cobrar: crea el cobro, reduce el saldo
 * y recalcula el estado (pendiente/parcial/pagada). Única puerta para mover el
 * saldo de una CxC. Espejo de AbonarService.
 *
 * Concurrencia: la cuenta se bloquea con lockForUpdate dentro de la
 * transacción, evitando que dos cobros simultáneos sobre-cobren el saldo.
 */
final class CobrarService
{
    private const int SCALE = 2;

    public function cobrar(
        CuentaPorCobrar $cuenta,
        string $monto,
        ?string $fecha = null,
        ?string $metodo = null,
        ?string $referencia = null,
        ?int $userId = null,
        ?string $notas = null,
    ): Cobro {
        if (bccomp($monto, '0', self::SCALE) <= 0) {
            throw CobroInvalidoException::montoNoPositivo($monto);
        }

        return DB::transaction(function () use ($cuenta, $monto, $fecha, $metodo, $referencia, $userId, $notas): Cobro {
            $cuentaBloqueada = CuentaPorCobrar::query()
                ->whereKey($cuenta->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $saldo = (string) $cuentaBloqueada->saldo;

            if (bccomp($monto, $saldo, self::SCALE) > 0) {
                throw CobroInvalidoException::excedeSaldo($monto, $saldo);
            }

            $cobro = Cobro::create([
                'cuenta_por_cobrar_id' => $cuentaBloqueada->id,
                'monto'                => $monto,
                'fecha'                => $fecha ?? now()->toDateString(),
                'metodo'               => $metodo,
                'referencia'           => $referencia,
                'user_id'              => $userId,
                'notas'                => $notas,
            ]);

            $nuevoSaldo = bcsub($saldo, $monto, self::SCALE);

            $cuentaBloqueada->saldo = $nuevoSaldo;
            $cuentaBloqueada->estado = $this->estadoSegunSaldo($nuevoSaldo, (string) $cuentaBloqueada->monto_original);
            $cuentaBloqueada->save();

            return $cobro;
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
