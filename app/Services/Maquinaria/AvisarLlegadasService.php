<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Models\AgendaMaquina;

/**
 * Aviso de llegada (decisión Mauricio 2026-07-15): campanita al encargado
 * cuando su máquina agendada llega dentro de la PRÓXIMA hora, para que
 * prepare el acceso y confirme la llegada.
 *
 * Lo dispara el scheduler cada 10 minutos (maquinaria:avisar-llegadas).
 * Idempotente por diseño: cada agendado avisa UNA sola vez — la marca
 * aviso_llegada_at se pone aunque la obra no tenga encargados (avisar es
 * best-effort, nunca se queda reintentando en el vacío).
 *
 * Lo que ya pasó no avisa: si el sistema estuvo caído y la hora de
 * llegada quedó atrás, un aviso tardío a las 3:00 PM de una máquina que
 * llegó a las 8:00 AM solo hace ruido.
 */
final readonly class AvisarLlegadasService
{
    public function __construct(private NotificadorMaquinaria $notificador) {}

    /**
     * Busca los agendados de HOY sin aviso cuya hora de llegada cae en
     * la próxima hora, notifica a los encargados y los marca.
     *
     * @return int Cuántos avisos se enviaron en esta pasada.
     */
    public function avisar(): int
    {
        $ahora = now();

        // La ventana no cruza medianoche: una llegada de mañana la avisa
        // la pasada de mañana (la fecha ancla es HOY).
        $limite = $ahora->copy()->addHour();

        if (! $limite->isSameDay($ahora)) {
            $limite = $ahora->copy()->endOfDay();
        }

        $porLlegar = AgendaMaquina::query()
            ->with(['maquina:id,nombre', 'proyecto:id,nombre'])
            ->whereDate('fecha', $ahora->toDateString())
            ->whereNull('aviso_llegada_at')
            ->whereNotNull('hora_entrada')
            ->whereBetween('hora_entrada', [$ahora->format('H:i:s'), $limite->format('H:i:s')])
            ->get();

        foreach ($porLlegar as $agendado) {
            $this->notificador->maquinaPorLlegar($agendado);

            $agendado->forceFill(['aviso_llegada_at' => now()])->save();
        }

        return $porLlegar->count();
    }
}
