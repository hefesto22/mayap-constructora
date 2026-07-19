<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Filament\Resources\Maquinas\MaquinaResource;
use App\Models\PlanMantenimiento;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Campanitas de mantenimiento preventivo — "a esta máquina ya le toca
 * el cambio de aceite".
 *
 * Destinatarios: gerencia y maquinaria (quien programa el taller).
 * Igual que cobranza: POR ROL, notifyNow (nunca sendToDatabase),
 * best-effort — sin usuarios con rol → silencio, jamás tumba al
 * scheduler.
 */
final class NotificadorMantenimiento
{
    /**
     * "Se acerca el cambio" — al cruzar el 90% del intervalo.
     */
    public function mantenimientoProximo(PlanMantenimiento $plan): void
    {
        $plan->loadMissing('maquina:id,codigo,nombre,horometro_actual,kilometraje_actual');

        $this->despachar(
            Notification::make()
                ->title('Mantenimiento próximo')
                ->body(
                    "{$plan->nombre} de {$plan->maquina->nombre} ({$plan->maquina->codigo}) "
                    ."está por tocar: {$plan->usoResumen()} ({$plan->intervaloResumen()})."
                )
                ->icon('heroicon-o-wrench-screwdriver')
                ->iconColor('warning')
                ->actions([$this->verMaquina($plan)]),
        );
    }

    /**
     * "Ya tocaba" — al llegar o pasarse del intervalo (100%+).
     */
    public function mantenimientoVencido(PlanMantenimiento $plan): void
    {
        $plan->loadMissing('maquina:id,codigo,nombre,horometro_actual,kilometraje_actual');

        $this->despachar(
            Notification::make()
                ->title('Mantenimiento VENCIDO')
                ->body(
                    "{$plan->nombre} de {$plan->maquina->nombre} ({$plan->maquina->codigo}) "
                    ."ya se pasó: {$plan->usoResumen()} ({$plan->intervaloResumen()}). Programar el cambio."
                )
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('danger')
                ->actions([$this->verMaquina($plan)]),
        );
    }

    private function verMaquina(PlanMantenimiento $plan): Action
    {
        return Action::make('ver')
            ->label('Ver máquina')
            ->url(MaquinaResource::getUrl('edit', ['record' => $plan->maquina]))
            ->button();
    }

    private function despachar(Notification $notificacion): void
    {
        $this->responsables()
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }

    /**
     * Busca por relación (NO con el scope `role()` de Spatie, que LANZA
     * si el rol no está sembrado): notificar es best-effort.
     *
     * @return Collection<int, User>
     */
    private function responsables(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::GERENCIA, Roles::MAQUINARIA]))
            ->where('is_active', true)
            ->get();
    }
}
