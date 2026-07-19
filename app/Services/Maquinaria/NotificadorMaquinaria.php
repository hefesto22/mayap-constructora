<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoSolicitudMaquina;
use App\Filament\Pages\CalendarioMaquinaria;
use App\Filament\Resources\SolicitudesMaquina\SolicitudMaquinaResource;
use App\Models\AgendaMaquina;
use App\Models\Proyecto;
use App\Models\SolicitudMaquina;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Notificaciones de campanita (database) de maquinaria — misma filosofía
 * que NotificadorRequisiciones: el sistema avisa a quien le toca, nadie
 * persigue a nadie por WhatsApp.
 *
 *  - Maquinaria agendada → encargados de la obra: qué máquina(s) y a qué
 *    hora llegan. Una sola campanita por lote (30 máquinas = 1 aviso,
 *    no 30 — una campanita que suena por todo termina ignorada).
 *  - Solicitud pendiente → rol maquinaria: hay que resolverla.
 *  - Solicitud resuelta (agendada/rechazada) → solicitante, encargados
 *    de la obra y rol maquinaria: en qué quedó.
 *
 * El actor nunca se auto-notifica. notifyNow (síncrono): respeta la
 * transacción del caller y no depende de un worker de colas.
 */
final class NotificadorMaquinaria
{
    /**
     * @param Collection<int, AgendaMaquina> $agendados
     */
    public function maquinariaAgendada(Proyecto $proyecto, Collection $agendados, ?int $actorId = null): void
    {
        if ($agendados->isEmpty()) {
            return;
        }

        // loadMissing vive en la colección ELOQUENT — los callers arman la
        // lista con collect() (colección base), así que se convierte aquí.
        $agendados = (new EloquentCollection($agendados->values()->all()))
            ->loadMissing('maquina:id,nombre');

        // Una línea por máquina: nombre, día(s) y hora de llegada.
        $lineas = $agendados
            ->groupBy('maquina_id')
            ->map(function (Collection $grupo): string {
                /** @var AgendaMaquina $primero */
                $primero = $grupo->first();
                $fechas = $grupo->pluck('fecha')->sort()->values();

                $rango = $fechas->count() === 1
                    ? 'el '.$fechas->first()->format('d/m/Y')
                    : 'del '.$fechas->first()->format('d/m').' al '.$fechas->last()->format('d/m/Y');

                $hora = $primero->horaEntrada12();

                return $primero->maquina->nombre." {$rango}"
                    .($hora !== null ? ", llega {$hora}" : '');
            })
            ->values();

        $detalle = $lineas->take(3)->join(' · ')
            .($lineas->count() > 3 ? ' · +'.($lineas->count() - 3).' más' : '');

        $notificacion = Notification::make()
            ->title('Maquinaria agendada a tu obra')
            ->body("{$proyecto->nombre}: {$detalle}")
            ->icon('heroicon-o-truck')
            ->actions([
                Action::make('ver')
                    ->label('Ver calendario')
                    ->url(CalendarioMaquinaria::getUrl())
                    ->button(),
            ]);

        // Best-effort: notificar jamás debe tumbar el agendado que lo
        // disparó. Sin encargados asignados → silencio y la vida sigue.
        $this->despachar($this->encargadosDe($proyecto), $notificacion, $actorId);
    }

    /**
     * "Tu máquina llega en menos de una hora": campanita a los encargados
     * de la obra para que preparen el acceso/terreno. La dispara el
     * scheduler (maquinaria:avisar-llegadas) — sin actor: es el sistema.
     */
    public function maquinaPorLlegar(AgendaMaquina $agendado): void
    {
        $agendado->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        $notificacion = Notification::make()
            ->title('Máquina por llegar a tu obra')
            ->body(
                "{$agendado->maquina->nombre} llega a {$agendado->proyecto->nombre}"
                .($agendado->horaEntrada12() !== null ? " a las {$agendado->horaEntrada12()}" : '')
                .' — en menos de una hora. Prepara el acceso y confirma cuando llegue.'
            )
            ->icon('heroicon-o-truck')
            ->iconColor('info')
            ->actions([
                Action::make('ver')
                    ->label('Ver calendario')
                    ->url(CalendarioMaquinaria::getUrl())
                    ->button(),
            ]);

        $this->despachar($this->encargadosDe($agendado->proyecto), $notificacion, null);
    }

    /**
     * El encargado confirmó que la máquina YA está en la obra: maquinaria
     * y gerencia se enteran (cierra el ciclo del aviso "llega en 1 hora").
     */
    public function llegadaConfirmada(AgendaMaquina $agendado, User $confirmador): void
    {
        $agendado->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        $notificacion = Notification::make()
            ->title('Máquina llegó a la obra')
            ->body(
                "{$agendado->maquina->nombre} ya está en {$agendado->proyecto->nombre} — "
                ."confirmó {$confirmador->name} a las ".now()->format('g:i A')
                .($agendado->horaEntrada12() !== null ? " (llegada prevista {$agendado->horaEntrada12()})" : '')
            )
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->actions([
                Action::make('ver')
                    ->label('Ver calendario')
                    ->url(CalendarioMaquinaria::getUrl())
                    ->button(),
            ]);

        $this->despachar(
            $this->usuariosConRol(Roles::MAQUINARIA, Roles::GERENCIA, Roles::RECEPCION),
            $notificacion,
            $confirmador->id,
        );
    }

    /**
     * El encargado confirmó que la máquina TERMINÓ en su obra: quedó
     * libre — la siguiente obra del día ya puede recibirla.
     */
    public function salidaConfirmada(AgendaMaquina $agendado, User $confirmador): void
    {
        $agendado->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        $notificacion = Notification::make()
            ->title('Máquina terminó en la obra')
            ->body(
                "{$agendado->maquina->nombre} terminó en {$agendado->proyecto->nombre} — "
                ."confirmó {$confirmador->name} a las ".now()->format('g:i A')
                .($agendado->llegada_confirmada_at !== null
                    ? ' (trabajó desde las '.$agendado->llegada_confirmada_at->format('g:i A').')'
                    : '')
                .'. La máquina queda libre.'
            )
            ->icon('heroicon-o-arrow-right-circle')
            ->iconColor('info')
            ->actions([
                Action::make('ver')
                    ->label('Ver calendario')
                    ->url(CalendarioMaquinaria::getUrl())
                    ->button(),
            ]);

        $this->despachar(
            $this->usuariosConRol(Roles::MAQUINARIA, Roles::GERENCIA, Roles::RECEPCION),
            $notificacion,
            $confirmador->id,
        );
    }

    /**
     * La agenda no pudo agendarla sola: al rol maquinaria le toca
     * resolverla (otra fecha, otra máquina o rechazo con motivo).
     */
    public function solicitudPendiente(SolicitudMaquina $solicitud, ?int $actorId = null): void
    {
        $solicitud->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        // Lo urgente entra gritando: maquinaria sabe qué resolver primero.
        $urgente = $solicitud->prioridad->esUrgente();

        $notificacion = Notification::make()
            ->title($urgente ? '⚠ Solicitud URGENTE de máquina por resolver' : 'Solicitud de máquina por resolver')
            ->body(
                "{$solicitud->codigo} · {$solicitud->maquina->nombre} para {$solicitud->proyecto->nombre} — "
                .$solicitud->rangoParaEl()
                .($solicitud->motivo !== null ? " — {$solicitud->motivo}" : '')
            )
            ->icon('heroicon-o-hand-raised')
            ->iconColor($urgente ? 'danger' : null)
            ->actions([
                Action::make('ver')
                    ->label('Ver solicitud')
                    ->url(SolicitudMaquinaResource::getUrl('view', ['record' => $solicitud]))
                    ->button(),
            ]);

        $this->despachar($this->usuariosConRol(Roles::MAQUINARIA, Roles::GERENCIA, Roles::RECEPCION), $notificacion, $actorId);
    }

    /**
     * En qué quedó: agendada (con día y hora de llegada) o rechazada (con
     * motivo). Se enteran el solicitante, los encargados de la obra y el
     * rol maquinaria — todos los que tocan esa máquina ese día.
     */
    public function solicitudResuelta(SolicitudMaquina $solicitud, ?int $actorId = null): void
    {
        $solicitud->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre', 'solicitante']);

        $agendada = $solicitud->estado === EstadoSolicitudMaquina::Agendada;

        $cuerpo = $agendada
            ? "{$solicitud->codigo} · {$solicitud->maquina->nombre} en {$solicitud->proyecto->nombre} — "
                .$solicitud->rangoParaEl()
                .($solicitud->horaLlegada12() !== null ? ', llega '.$solicitud->horaLlegada12() : '')
            : "{$solicitud->codigo} · {$solicitud->maquina->nombre} para {$solicitud->proyecto->nombre}"
                .($solicitud->motivo !== null ? " — {$solicitud->motivo}" : '');

        $notificacion = Notification::make()
            ->title($agendada ? 'Solicitud de máquina agendada' : 'Solicitud de máquina rechazada')
            ->body($cuerpo)
            ->icon($agendada ? 'heroicon-o-calendar-days' : 'heroicon-o-x-circle')
            ->actions([
                Action::make('ver')
                    ->label('Ver solicitud')
                    ->url(SolicitudMaquinaResource::getUrl('view', ['record' => $solicitud]))
                    ->button(),
            ]);

        $destinatarios = $this->encargadosDe($solicitud->proyecto)
            ->merge($this->usuariosConRol(Roles::MAQUINARIA, Roles::RECEPCION))
            ->when($solicitud->solicitante !== null, fn (Collection $c) => $c->push($solicitud->solicitante));

        $this->despachar($destinatarios, $notificacion, $actorId);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * @param Collection<int, User> $destinatarios
     */
    private function despachar(Collection $destinatarios, Notification $notificacion, ?int $actorId): void
    {
        $destinatarios
            ->unique('id')
            ->reject(fn (User $user): bool => $user->id === $actorId)
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }

    /**
     * @return Collection<int, User>
     */
    private function encargadosDe(Proyecto $proyecto): Collection
    {
        return $proyecto->encargados()
            ->where('is_active', true)
            ->get();
    }

    /**
     * Busca por relación (NO con el scope `role()` de Spatie, que LANZA
     * si el rol no está sembrado): notificar es best-effort — jamás debe
     * tumbar la operación que lo disparó.
     *
     * @return Collection<int, User>
     */
    private function usuariosConRol(string ...$roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->where('is_active', true)
            ->get();
    }
}
