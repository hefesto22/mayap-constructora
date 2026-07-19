<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

use App\Models\CuentaPorCobrar;
use Illuminate\Support\Carbon;

/**
 * Recordatorios escalonados de cobranza (decisión Mauricio 2026-07-16):
 * campanita a gerencia/recepción a los 7 días, 3 días y el DÍA del
 * vencimiento de cada cuenta por cobrar con saldo, más un aviso único
 * cuando la cuenta ya venció impaga.
 *
 * Lo dispara el scheduler una vez al día (cobranza:avisar-vencimientos).
 * Idempotente por diseño: `ultimo_aviso_dias` guarda el escalón ya
 * notificado y solo se avanza (7 → 3 → 0 → -1) — correr el comando
 * dos veces el mismo día no duplica campanitas.
 *
 * Si el sistema estuvo caído y una cuenta saltó escalones, avisa UNA
 * vez con el escalón real de hoy (no tres avisos atrasados en cadena).
 * Cuentas pagadas (saldo 0) salen solas del radar por el scope.
 */
final readonly class AvisarVencimientosService
{
    /** Marca de "ya avisé que venció". */
    private const int VENCIDA = -1;

    public function __construct(private NotificadorCobranza $notificador) {}

    /**
     * Recorre las cuentas con saldo cuyo vencimiento cae dentro del
     * radar (próximos 7 días o ya vencidas), avisa el escalón que
     * corresponde y lo marca.
     *
     * @return int Cuántos avisos se enviaron en esta pasada.
     */
    public function avisar(): int
    {
        $hoy = today();

        $cuentas = CuentaPorCobrar::query()
            ->with('cliente:id,nombre')
            ->where('saldo', '>', 0)
            ->whereDate('fecha_vencimiento', '<=', $hoy->copy()->addDays(7))
            ->get();

        $avisos = 0;

        foreach ($cuentas as $cuenta) {
            $escalon = $this->escalonDeHoy($cuenta, $hoy);

            if ($escalon === null || ! $this->tocaAvisar($cuenta, $escalon)) {
                continue;
            }

            match (true) {
                $escalon === self::VENCIDA => $this->notificador->cuentaVencida($cuenta),
                $escalon === 0             => $this->notificador->cuentaVenceHoy($cuenta),
                default                    => $this->notificador->cuentaPorVencer($cuenta, $escalon),
            };

            $cuenta->forceFill(['ultimo_aviso_dias' => $escalon])->save();
            $avisos++;
        }

        return $avisos;
    }

    /**
     * Escalón que corresponde HOY según los días restantes:
     * vencida → -1; hoy → 0; 1..3 → 3; 4..7 → 7; más lejos → null.
     */
    private function escalonDeHoy(CuentaPorCobrar $cuenta, Carbon $hoy): ?int
    {
        $restantes = (int) $hoy->diffInDays($cuenta->fecha_vencimiento, false);

        return match (true) {
            $restantes < 0   => self::VENCIDA,
            $restantes === 0 => 0,
            $restantes <= 3  => 3,
            $restantes <= 7  => 7,
            default          => null,
        };
    }

    /**
     * ¿Este escalón aún no se avisó? Solo se avanza hacia el
     * vencimiento: 7 → 3 → 0 → -1, sin repetir ni retroceder.
     */
    private function tocaAvisar(CuentaPorCobrar $cuenta, int $escalon): bool
    {
        return $cuenta->ultimo_aviso_dias === null
            || $cuenta->ultimo_aviso_dias > $escalon;
    }
}
