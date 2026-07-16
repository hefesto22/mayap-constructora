<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\User;
use App\Support\Roles;
use BezhanSalleh\FilamentShield\Support\Utils;

/**
 * Confirmación de llegada Y salida (decisión Mauricio 2026-07-15): el
 * encargado — que es quien está parado en la obra — marca con un click
 * en el calendario que la máquina YA llegó, y después que YA terminó.
 * Queda quién y a qué hora en ambos extremos, y el rol maquinaria
 * recibe la campanita (cierra el ciclo del aviso "llega en 1 hora").
 *
 * Reglas:
 *  - Confirma el encargado de ESA obra (o maquinaria/gerencia de respaldo).
 *  - Solo el día del agendado en adelante — no se confirma el futuro.
 *  - Una sola vez cada extremo: la confirmación es un hecho, no un toggle.
 *  - UNA OBRA A LA VEZ: mientras la máquina tenga una llegada confirmada
 *    sin salida, ninguna otra obra puede confirmar su llegada — la
 *    máquina no está en dos lugares al mismo tiempo.
 */
final readonly class ConfirmarLlegadaService
{
    public function __construct(private NotificadorMaquinaria $notificador) {}

    public function confirmar(AgendaMaquina $agendado, User $user): AgendaMaquina
    {
        $agendado->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        if ($agendado->llegada_confirmada_at !== null) {
            throw AgendaInvalidaException::llegadaYaConfirmada(
                $agendado->llegada_confirmada_at->format('d/m/Y g:i A')
            );
        }

        if ($agendado->fecha->isFuture()) {
            throw AgendaInvalidaException::llegadaAntesDeTiempo($agendado->fecha->format('d/m/Y'));
        }

        if (! $this->puedeConfirmar($agendado, $user)) {
            throw AgendaInvalidaException::confirmaSoloLaObra();
        }

        // La máquina no está en dos lugares a la vez: si sigue "adentro"
        // de otra obra (llegó y nadie confirmó que terminó), primero se
        // cierra allá.
        $abierto = $this->compromisoAbierto($agendado);

        if ($abierto !== null) {
            throw AgendaInvalidaException::sigueTrabajandoEnOtraObra(
                $agendado->maquina->nombre,
                $abierto->proyecto->nombre,
                $abierto->llegada_confirmada_at?->format('g:i A') ?? '',
            );
        }

        $agendado->forceFill([
            'llegada_confirmada_at'  => now(),
            'llegada_confirmada_por' => $user->id,
        ])->save();

        // Maquinaria se entera de que su máquina ya está en la obra.
        $this->notificador->llegadaConfirmada($agendado, $user);

        return $agendado->refresh();
    }

    /**
     * "Ya terminó aquí": libera a la máquina para que la siguiente obra
     * del día pueda confirmar su llegada.
     */
    public function confirmarSalida(AgendaMaquina $agendado, User $user): AgendaMaquina
    {
        $agendado->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        if ($agendado->llegada_confirmada_at === null) {
            throw AgendaInvalidaException::salidaSinLlegada();
        }

        if ($agendado->salida_confirmada_at !== null) {
            throw AgendaInvalidaException::salidaYaConfirmada(
                $agendado->salida_confirmada_at->format('d/m/Y g:i A')
            );
        }

        if (! $this->puedeConfirmar($agendado, $user)) {
            throw AgendaInvalidaException::confirmaSoloLaObra();
        }

        $agendado->forceFill([
            'salida_confirmada_at'  => now(),
            'salida_confirmada_por' => $user->id,
        ])->save();

        // Maquinaria sabe que la máquina quedó libre (y la siguiente obra
        // del día ya puede confirmar su llegada).
        $this->notificador->salidaConfirmada($agendado, $user);

        return $agendado->refresh();
    }

    /**
     * El compromiso ABIERTO de esta máquina ese día en OTRA obra:
     * llegada confirmada sin salida. Null = la máquina está libre.
     */
    public function compromisoAbierto(AgendaMaquina $agendado): ?AgendaMaquina
    {
        return AgendaMaquina::query()
            ->with('proyecto:id,nombre')
            ->where('maquina_id', $agendado->maquina_id)
            ->whereKeyNot($agendado->id)
            ->whereDate('fecha', $agendado->fecha->toDateString())
            ->whereNotNull('llegada_confirmada_at')
            ->whereNull('salida_confirmada_at')
            ->first();
    }

    /**
     * ¿Este usuario puede confirmar ESTE agendado? El encargado de la
     * obra es el caso normal; maquinaria/gerencia/super de respaldo.
     */
    public function puedeConfirmar(AgendaMaquina $agendado, User $user): bool
    {
        return $agendado->proyecto->esEncargado($user)
            || $user->hasAnyRole([Roles::MAQUINARIA, Roles::GERENCIA, Utils::getSuperAdminName()]);
    }
}
