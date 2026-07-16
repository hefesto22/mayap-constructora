<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Models\AgendaMaquina;
use App\Models\Maquina;

/**
 * Cuando una máquina entra a mantenimiento, sus agendados FUTUROS quedan
 * imposibles de cumplir. Este service los resuelve (dentro de la misma
 * transacción del mantenimiento):
 *
 *  - CON sustituta: se transfieren a la sustituta (misma obra, mismos
 *    días/horas). Si la sustituta ya estaba agendada a esa obra ese día,
 *    ese agendado se cancela y se reporta (sin dobles compromisos).
 *  - SIN sustituta: se cancelan todos y se reporta el detalle, para
 *    reagendar cuando salga del taller (o gestionar un alquiler).
 *
 * Incluye el agendado del MISMO día de la avería: si alcanzó a trabajar
 * horas, esa realidad ya vive en el parte (verde) — el plan azul sobra.
 */
final class ReagendarPorMantenimientoService
{
    /**
     * @return array{transferidos: int, cancelados: int, detalle: list<string>}
     */
    public function resolver(Maquina $maquina, string $desdeFecha, ?Maquina $sustituta): array
    {
        $agendados = AgendaMaquina::query()
            ->with('proyecto:id,nombre')
            ->where('maquina_id', $maquina->id)
            ->whereDate('fecha', '>=', $desdeFecha)
            ->orderBy('fecha')
            ->lockForUpdate()
            ->get();

        $transferidos = 0;
        $cancelados = 0;
        $detalle = [];

        foreach ($agendados as $agendado) {
            $dia = $agendado->fecha->format('d/m');
            $obra = $agendado->proyecto->nombre;

            if ($sustituta !== null && ! $this->sustitutaOcupada($sustituta, $agendado)) {
                $agendado->update(['maquina_id' => $sustituta->id]);
                $transferidos++;
                $detalle[] = "{$dia} · {$obra} → {$sustituta->nombre}";

                continue;
            }

            $agendado->delete();
            $cancelados++;
            $detalle[] = $sustituta !== null
                ? "{$dia} · {$obra} — cancelado (la sustituta ya estaba agendada ahí)"
                : "{$dia} · {$obra} — cancelado";
        }

        return [
            'transferidos' => $transferidos,
            'cancelados'   => $cancelados,
            'detalle'      => $detalle,
        ];
    }

    /**
     * ¿La sustituta ya tiene un agendado para esa obra ese día?
     * (unique maquina+proyecto+fecha: transferirlo duplicaría).
     */
    private function sustitutaOcupada(Maquina $sustituta, AgendaMaquina $agendado): bool
    {
        return AgendaMaquina::query()
            ->where('maquina_id', $sustituta->id)
            ->where('proyecto_id', $agendado->proyecto_id)
            ->whereDate('fecha', $agendado->fecha)
            ->exists();
    }
}
