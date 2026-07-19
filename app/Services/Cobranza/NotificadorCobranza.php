<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

use App\Filament\Resources\CuentasPorCobrar\CuentaPorCobrarResource;
use App\Models\CuentaPorCobrar;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Campanitas de cobranza — quién debe y cuándo vence.
 *
 * Destinatarios: gerencia y recepción (la oficina que gestiona el
 * cobro). Igual que en maquinaria, las campanitas van POR ROL: un
 * super_admin sin el rol no las recibe.
 *
 * notifyNow (síncrono): respeta la regla del catálogo anti-errores —
 * nunca sendToDatabase. Best-effort: notificar jamás tumba al
 * scheduler que lo disparó; sin usuarios con rol → silencio.
 */
final class NotificadorCobranza
{
    /**
     * "La cuenta de FULANO vence en N días" — recordatorio escalonado
     * (7, 3) antes del vencimiento.
     */
    public function cuentaPorVencer(CuentaPorCobrar $cuenta, int $dias): void
    {
        $cuenta->loadMissing('cliente:id,nombre');

        $cuando = $dias === 7 ? 'en una semana' : "en {$dias} días";

        $this->despachar(
            Notification::make()
                ->title('Cuenta por cobrar próxima a vencer')
                ->body(
                    "{$cuenta->cliente->nombre} debe L ".number_format((float) $cuenta->saldo, 2)
                    ." ({$cuenta->codigo}) — vence {$cuando}, el "
                    .$cuenta->fecha_vencimiento->format('d/m/Y').'.'
                )
                ->icon('heroicon-o-banknotes')
                ->iconColor('warning')
                ->actions([$this->verCuenta($cuenta)]),
        );
    }

    /**
     * "La cuenta de FULANO vence HOY" — el día del vencimiento.
     */
    public function cuentaVenceHoy(CuentaPorCobrar $cuenta): void
    {
        $cuenta->loadMissing('cliente:id,nombre');

        $this->despachar(
            Notification::make()
                ->title('Cuenta por cobrar vence HOY')
                ->body(
                    "{$cuenta->cliente->nombre} debe L ".number_format((float) $cuenta->saldo, 2)
                    ." ({$cuenta->codigo}) — hoy es la fecha máxima de pago."
                )
                ->icon('heroicon-o-banknotes')
                ->iconColor('danger')
                ->actions([$this->verCuenta($cuenta)]),
        );
    }

    /**
     * "La cuenta de FULANO ya venció" — una sola vez al detectar el
     * atraso (cuentas que vencieron sin que corriera el aviso del día,
     * o que siguieron impagas).
     */
    public function cuentaVencida(CuentaPorCobrar $cuenta): void
    {
        $cuenta->loadMissing('cliente:id,nombre');

        $dias = (int) $cuenta->fecha_vencimiento->diffInDays(today());

        $this->despachar(
            Notification::make()
                ->title('Cuenta por cobrar VENCIDA')
                ->body(
                    "{$cuenta->cliente->nombre} debe L ".number_format((float) $cuenta->saldo, 2)
                    ." ({$cuenta->codigo}) — venció el "
                    .$cuenta->fecha_vencimiento->format('d/m/Y')
                    .($dias > 0 ? " ({$dias} día".($dias === 1 ? '' : 's').' de atraso)' : '')
                    .'. Gestionar el cobro.'
                )
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('danger')
                ->actions([$this->verCuenta($cuenta)]),
        );
    }

    private function verCuenta(CuentaPorCobrar $cuenta): Action
    {
        return Action::make('ver')
            ->label('Ver cuenta')
            ->url(CuentaPorCobrarResource::getUrl('view', ['record' => $cuenta]))
            ->button();
    }

    private function despachar(Notification $notificacion): void
    {
        $this->cobradores()
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }

    /**
     * Busca por relación (NO con el scope `role()` de Spatie, que LANZA
     * si el rol no está sembrado): notificar es best-effort.
     *
     * @return Collection<int, User>
     */
    private function cobradores(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::GERENCIA, Roles::RECEPCION]))
            ->where('is_active', true)
            ->get();
    }
}
