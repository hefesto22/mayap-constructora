<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCuentaPorPagar;
use App\Exceptions\Compras\AbonoInvalidoException;
use App\Models\Abono;
use App\Models\CuentaPorPagar;
use Illuminate\Support\Facades\DB;

/**
 * Registra abonos contra una cuenta por pagar: crea el abono, reduce el saldo
 * y recalcula el estado (pendiente/parcial/pagada). Única puerta para mover
 * el saldo de una CxP.
 *
 * `fotoComprobante` (decisión Mauricio 2026-07-20): la foto del comprobante
 * de la transferencia — una por abono, ya convertida a WebP por el
 * formulario. Se archiva en el reporte mensual de pagos y luego se purga.
 *
 * Concurrencia: la cuenta se bloquea con lockForUpdate dentro de la
 * transacción, evitando que dos abonos simultáneos sobre-paguen el saldo.
 */
final class AbonarService
{
    private const int SCALE = 2;

    public function abonar(
        CuentaPorPagar $cuenta,
        string $monto,
        ?string $fecha = null,
        ?string $metodo = null,
        ?string $referencia = null,
        ?int $userId = null,
        ?string $notas = null,
        ?string $fotoComprobante = null,
    ): Abono {
        if (bccomp($monto, '0', self::SCALE) <= 0) {
            throw AbonoInvalidoException::montoNoPositivo($monto);
        }

        return DB::transaction(function () use ($cuenta, $monto, $fecha, $metodo, $referencia, $userId, $notas, $fotoComprobante): Abono {
            // Bloquea la fila de la cuenta para evitar sobrepago concurrente.
            $cuentaBloqueada = CuentaPorPagar::query()
                ->whereKey($cuenta->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $saldo = (string) $cuentaBloqueada->saldo;

            if (bccomp($monto, $saldo, self::SCALE) > 0) {
                throw AbonoInvalidoException::excedeSaldo($monto, $saldo);
            }

            $abono = Abono::create([
                'cuenta_por_pagar_id' => $cuentaBloqueada->id,
                'monto'               => $monto,
                'fecha'               => $fecha ?? now()->toDateString(),
                'metodo'              => $metodo,
                'referencia'          => $referencia,
                'foto_comprobante'    => $fotoComprobante,
                'user_id'             => $userId,
                'notas'               => $notas,
            ]);

            $nuevoSaldo = bcsub($saldo, $monto, self::SCALE);

            $cuentaBloqueada->saldo = $nuevoSaldo;
            $cuentaBloqueada->estado = $this->estadoSegunSaldo($nuevoSaldo, (string) $cuentaBloqueada->monto_original);
            $cuentaBloqueada->save();

            return $abono;
        });
    }

    private function estadoSegunSaldo(string $saldo, string $montoOriginal): EstadoCuentaPorPagar
    {
        if (bccomp($saldo, '0', self::SCALE) <= 0) {
            return EstadoCuentaPorPagar::Pagada;
        }

        if (bccomp($saldo, $montoOriginal, self::SCALE) < 0) {
            return EstadoCuentaPorPagar::Parcial;
        }

        return EstadoCuentaPorPagar::Pendiente;
    }
}
