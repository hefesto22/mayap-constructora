<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\User;

/**
 * Contingencia "NO llegó" (decisión Mauricio 2026-07-20): cuando la
 * fecha de una agenda pasó sin llegada confirmada, el calendario la
 * pinta ROJA hasta que alguien resuelva qué pasó. Si la máquina de
 * verdad nunca fue, aquí se deja la constancia: quién lo marcó, cuándo
 * y por qué. El evento rojo se retira, maquinaria/gerencia reciben la
 * campanita y el motivo queda en la bitácora de la obra.
 *
 * Puede marcarla quien puede confirmar la llegada (el encargado de ESA
 * obra, o maquinaria/gerencia de respaldo) — misma regla de siempre.
 */
final readonly class MarcarNoLlegoAgendaService
{
    public function __construct(
        private ConfirmarLlegadaService $llegadas,
        private NotificadorMaquinaria $notificador,
    ) {}

    public function marcar(AgendaMaquina $agendado, string $motivo, User $user): AgendaMaquina
    {
        $agendado->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw AgendaInvalidaException::noLlegoSinMotivo();
        }

        // "No llegó" es un juicio sobre el pasado: hoy la máquina
        // todavía puede llegar.
        if ($agendado->fecha->isToday() || $agendado->fecha->isFuture()) {
            throw AgendaInvalidaException::noLlegoEnFuturo($agendado->fecha->format('d/m/Y'));
        }

        if ($agendado->llegada_confirmada_at !== null) {
            throw AgendaInvalidaException::noLlegoConLlegada(
                $agendado->llegada_confirmada_at->format('d/m/Y g:i A')
            );
        }

        if ($agendado->no_llego_at !== null) {
            throw AgendaInvalidaException::noLlegoYaMarcado(
                $agendado->no_llego_at->format('d/m/Y g:i A')
            );
        }

        if (! $this->llegadas->puedeConfirmar($agendado, $user)) {
            throw AgendaInvalidaException::confirmaSoloLaObra();
        }

        $agendado->forceFill([
            'no_llego_at'     => now(),
            'no_llego_por'    => $user->id,
            'no_llego_motivo' => mb_strtoupper($motivo),
        ])->save();

        // La constancia queda en la bitácora de la obra…
        activity('maquinaria')
            ->performedOn($agendado->proyecto)
            ->causedBy($user->id)
            ->withProperties([
                'maquina' => $agendado->maquina->nombre,
                'fecha'   => $agendado->fecha->format('d/m/Y'),
                'motivo'  => $agendado->no_llego_motivo,
            ])
            ->event('maquina_no_llego')
            ->log("{$agendado->maquina->nombre} NO llegó a {$agendado->proyecto->nombre} el {$agendado->fecha->format('d/m/Y')}: {$agendado->no_llego_motivo}");

        // …y maquinaria/gerencia se enteran con la campanita.
        $this->notificador->maquinaNoLlego($agendado, $user);

        return $agendado->refresh();
    }
}
