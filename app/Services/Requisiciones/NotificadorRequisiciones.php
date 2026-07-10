<?php

declare(strict_types=1);

namespace App\Services\Requisiciones;

use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Requisiciones\RequisicionResource;
use App\Models\Requisicion;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Notificaciones de campanita (database) del flujo de requisiciones —
 * ÚNICA fuente de "quién se entera de qué". El sistema avisa al rol que
 * tiene el siguiente paso; nadie persigue a nadie por WhatsApp:
 *
 *  - Solicitada (creación)  → bodegueros: "por autorizar".
 *  - Autorizada             → encargados de la obra: "pedido autorizado".
 *  - EnTransito             → encargados de la obra: "material en camino".
 *  - RequisicionCompra      → recepción: "sin stock, realizar compra".
 *  - Recibida               → bodegueros: "confirmada por la obra, conciliar".
 *  - Discrepancia           → bodegueros + gerencia: "no cuadró".
 *  - Rechazada              → solicitante y encargados de la obra.
 *
 * Despachada y Cerrada no notifican: son pasos del MISMO actor que ya
 * está en pantalla (evitar ruido — una campanita que suena por todo
 * termina ignorada).
 *
 * El actor de la transición nunca se auto-notifica.
 */
final class NotificadorRequisiciones
{
    public function nuevaSolicitud(Requisicion $requisicion, ?int $actorId = null): void
    {
        $this->enviar(
            destinatarios: $this->usuariosConRol(Roles::BODEGUERO),
            requisicion: $requisicion,
            titulo: 'Nueva requisición por autorizar',
            actorId: $actorId,
        );
    }

    public function transicion(Requisicion $requisicion, EstadoRequisicion $destino, ?int $actorId = null): void
    {
        [$destinatarios, $titulo] = match ($destino) {
            EstadoRequisicion::Autorizada        => [$this->encargadosDeLaObra($requisicion), 'Requisición autorizada'],
            EstadoRequisicion::EnTransito        => [$this->encargadosDeLaObra($requisicion), 'Material en camino a tu obra'],
            EstadoRequisicion::RequisicionCompra => [$this->usuariosConRol(Roles::RECEPCION), 'Requisición sin stock — realizar compra'],
            EstadoRequisicion::Recibida          => [$this->usuariosConRol(Roles::BODEGUERO), 'Entrega confirmada por la obra — conciliar'],
            EstadoRequisicion::Discrepancia      => [$this->usuariosConRol(Roles::BODEGUERO, Roles::GERENCIA), 'Discrepancia en la entrega'],
            EstadoRequisicion::Rechazada         => [$this->solicitanteYEncargados($requisicion), 'Requisición rechazada'],
            default                              => [collect(), ''],
        };

        if ($titulo === '') {
            return;
        }

        $this->enviar($destinatarios, $requisicion, $titulo, $actorId);
    }

    /**
     * @param Collection<int, User> $destinatarios
     */
    private function enviar(Collection $destinatarios, Requisicion $requisicion, string $titulo, ?int $actorId): void
    {
        $requisicion->loadMissing('proyecto:id,codigo,nombre');

        $notificacion = Notification::make()
            ->title($titulo)
            ->body("{$requisicion->codigo} · {$requisicion->proyecto->nombre}")
            ->icon('heroicon-o-clipboard-document-list')
            ->actions([
                Action::make('ver')
                    ->label('Ver requisición')
                    ->url(RequisicionResource::getUrl('view', ['record' => $requisicion]))
                    ->button(),
            ]);

        // notifyNow (SÍNCRONO): la DatabaseNotification de Filament es
        // ShouldQueue — con QUEUE_CONNECTION=redis y sin worker, las
        // campanitas se quedaban atascadas en la cola. Además, síncrona
        // respeta la transacción del caller: rollback = sin avisos.
        $destinatarios
            ->unique('id')
            ->reject(fn (User $user): bool => $user->id === $actorId)
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }

    /**
     * Busca por relación (NO con el scope `role()` de Spatie, que LANZA
     * RoleDoesNotExist si el rol no está sembrado): notificar es un efecto
     * secundario best-effort — jamás debe tumbar la transición que lo
     * disparó. Sin rol o sin usuarios → colección vacía y la vida sigue.
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

    /**
     * @return Collection<int, User>
     */
    private function encargadosDeLaObra(Requisicion $requisicion): Collection
    {
        $requisicion->loadMissing('proyecto.encargados');

        return $requisicion->proyecto->encargados
            ->where('is_active', true)
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function solicitanteYEncargados(Requisicion $requisicion): Collection
    {
        $requisicion->loadMissing('solicitante');

        return $this->encargadosDeLaObra($requisicion)
            ->when(
                $requisicion->solicitante !== null,
                fn (Collection $usuarios): Collection => $usuarios->push($requisicion->solicitante),
            );
    }
}
