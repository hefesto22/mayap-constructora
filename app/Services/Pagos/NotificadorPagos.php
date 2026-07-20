<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Filament\Resources\CuentasPorPagar\CuentaPorPagarResource;
use App\Models\CuentaPorPagar;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Campanitas de pagos a proveedores — cuánto debemos y cuándo vence.
 * Espejo exacto del NotificadorCobranza, pero del lado de las deudas.
 *
 * Destinatarios: gerencia y recepción (la oficina que coordina los
 * pagos). Las campanitas van POR ROL: un super_admin sin el rol no
 * las recibe.
 *
 * notifyNow (síncrono): respeta la regla del catálogo anti-errores —
 * nunca sendToDatabase. Best-effort: notificar jamás tumba al
 * scheduler que lo disparó; sin usuarios con rol → silencio.
 */
final class NotificadorPagos
{
    /**
     * "Debemos a FULANO y vence en N días" — recordatorio escalonado
     * (7, 3) antes del vencimiento.
     */
    public function cuentaPorVencer(CuentaPorPagar $cuenta, int $dias): void
    {
        $cuenta->loadMissing('proveedor:id,nombre', 'compra:id,codigo');

        $cuando = $dias === 7 ? 'en una semana' : "en {$dias} días";

        $this->despachar(
            Notification::make()
                ->title('Pago a proveedor próximo a vencer')
                ->body(
                    'Debemos L '.number_format((float) $cuenta->saldo, 2)
                    ." a {$cuenta->proveedor->nombre} ({$cuenta->compra->codigo}) — vence {$cuando}, el "
                    .$cuenta->fecha_vencimiento->format('d/m/Y').'.'
                )
                ->icon('heroicon-o-banknotes')
                ->iconColor('warning')
                ->actions([$this->verCuenta($cuenta)]),
        );
    }

    /**
     * "El pago a FULANO vence HOY" — el día del vencimiento.
     */
    public function cuentaVenceHoy(CuentaPorPagar $cuenta): void
    {
        $cuenta->loadMissing('proveedor:id,nombre', 'compra:id,codigo');

        $this->despachar(
            Notification::make()
                ->title('Pago a proveedor vence HOY')
                ->body(
                    'Debemos L '.number_format((float) $cuenta->saldo, 2)
                    ." a {$cuenta->proveedor->nombre} ({$cuenta->compra->codigo}) — hoy es la fecha máxima de pago."
                )
                ->icon('heroicon-o-banknotes')
                ->iconColor('danger')
                ->actions([$this->verCuenta($cuenta)]),
        );
    }

    /**
     * "El pago a FULANO ya venció" — una sola vez al detectar el
     * atraso (cuentas que vencieron sin que corriera el aviso del día,
     * o que siguieron impagas).
     */
    public function cuentaVencida(CuentaPorPagar $cuenta): void
    {
        $cuenta->loadMissing('proveedor:id,nombre', 'compra:id,codigo');

        $dias = (int) $cuenta->fecha_vencimiento->diffInDays(today());

        $this->despachar(
            Notification::make()
                ->title('Pago a proveedor VENCIDO')
                ->body(
                    'Debemos L '.number_format((float) $cuenta->saldo, 2)
                    ." a {$cuenta->proveedor->nombre} ({$cuenta->compra->codigo}) — venció el "
                    .$cuenta->fecha_vencimiento->format('d/m/Y')
                    .($dias > 0 ? " ({$dias} día".($dias === 1 ? '' : 's').' de atraso)' : '')
                    .'. Coordinar el pago.'
                )
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('danger')
                ->actions([$this->verCuenta($cuenta)]),
        );
    }

    private function verCuenta(CuentaPorPagar $cuenta): Action
    {
        return Action::make('ver')
            ->label('Ver cuenta')
            ->url(CuentaPorPagarResource::getUrl('view', ['record' => $cuenta]))
            ->button();
    }

    private function despachar(Notification $notificacion): void
    {
        $this->oficina()
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }

    /**
     * Busca por relación (NO con el scope `role()` de Spatie, que LANZA
     * si el rol no está sembrado): notificar es best-effort.
     *
     * @return Collection<int, User>
     */
    private function oficina(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::GERENCIA, Roles::RECEPCION]))
            ->where('is_active', true)
            ->get();
    }
}
