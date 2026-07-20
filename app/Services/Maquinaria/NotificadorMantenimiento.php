<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Filament\Resources\Mantenimientos\MantenimientoMaquinaResource;
use App\Filament\Resources\Maquinas\MaquinaResource;
use App\Models\MantenimientoMaquina;
use App\Models\PlanMantenimiento;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Campanitas de mantenimiento — preventivo ("ya le toca el cambio de
 * aceite") y correctivo ("los repuestos deberían estar llegando").
 *
 * Destinatarios: gerencia, maquinaria y recepción (la comodín que
 * cubre cuando el titular no está — decisión Mauricio 2026-07-19).
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

    /**
     * "Los repuestos deberían estar llegando" — el día de la fecha
     * estimada de recepción (o al detectar que ya pasó sin avisarse).
     */
    public function repuestosDeberianLlegar(MantenimientoMaquina $mantenimiento): void
    {
        $mantenimiento->loadMissing('maquina:id,codigo,nombre');

        $estimada = $mantenimiento->fecha_estimada_repuestos;
        $cuando = $estimada !== null && $estimada->isBefore(today())
            ? 'deberían haber llegado el '.$estimada->format('d/m/Y')
            : 'deberían llegar HOY';

        $this->despachar(
            Notification::make()
                ->title('Repuestos por llegar')
                ->body(
                    "Los repuestos de {$mantenimiento->maquina->nombre} "
                    ."({$mantenimiento->codigo}) {$cuando}. "
                    .'Confirmar con el proveedor y continuar la reparación.'
                )
                ->icon('heroicon-o-shopping-cart')
                ->iconColor('warning')
                ->actions([$this->verMantenimiento($mantenimiento)]),
        );
    }

    /**
     * "Esta es la reparación más importante" — al cambiar la prioridad
     * (decisión Mauricio 2026-07-20): el taller sabe cuál atacar primero.
     */
    public function prioridadCambiada(MantenimientoMaquina $mantenimiento, ?string $motivo = null): void
    {
        $mantenimiento->loadMissing('maquina:id,codigo,nombre');

        $prioridad = $mantenimiento->prioridad;

        $this->despachar(
            Notification::make()
                ->title("Prioridad de reparación: {$prioridad->getLabel()}")
                ->body(
                    "{$mantenimiento->maquina->nombre} ({$mantenimiento->codigo}) ahora es prioridad {$prioridad->getLabel()}."
                    .($motivo !== null && $motivo !== '' ? " Motivo: {$motivo}" : '')
                )
                ->icon($prioridad->getIcon())
                ->iconColor($prioridad->getColor())
                ->actions([$this->verMantenimiento($mantenimiento)]),
        );
    }

    private function verMaquina(PlanMantenimiento $plan): Action
    {
        return Action::make('ver')
            ->label('Ver máquina')
            ->url(MaquinaResource::getUrl('edit', ['record' => $plan->maquina]))
            ->button();
    }

    private function verMantenimiento(MantenimientoMaquina $mantenimiento): Action
    {
        return Action::make('ver')
            ->label('Ver mantenimiento')
            ->url(MantenimientoMaquinaResource::getUrl('view', ['record' => $mantenimiento]))
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
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::GERENCIA, Roles::MAQUINARIA, Roles::RECEPCION]))
            ->where('is_active', true)
            ->get();
    }
}
