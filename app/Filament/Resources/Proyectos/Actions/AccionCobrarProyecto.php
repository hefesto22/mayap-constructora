<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Actions;

use App\Filament\Resources\CuentasPorCobrar\Actions\AccionCobrar;
use App\Models\CuentaPorCobrar;
use App\Models\Proyecto;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * "Registrar cobro" DESDE el proyecto — el cliente de renta paga su
 * anticipo o un abono y la oficina lo anota ahí mismo, sin brincar a
 * Cuentas por Cobrar. Reutiliza el formulario y la ejecución de
 * AccionCobrar (CobrarService sigue siendo la única puerta del saldo).
 *
 * Cobra la cuenta PENDIENTE del proyecto más próxima a vencer (una
 * renta tiene una sola CxC, que crece con extensiones y extras). Con
 * varias cuentas pendientes, el modal dice cuál se está cobrando; los
 * casos raros se gestionan desde el módulo de Cuentas por Cobrar.
 *
 * Visible solo para quien puede actualizar cuentas por cobrar (misma
 * policy que el módulo) — el encargado de obra no ve dinero.
 */
final class AccionCobrarProyecto
{
    public static function make(): Action
    {
        return Action::make('cobrar_proyecto')
            ->label('Registrar cobro')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(function (?Proyecto $record): bool {
                if ($record === null) {
                    return false;
                }

                $cuenta = $record->cuentaPorCobrarPendiente();

                return $cuenta !== null
                    && (auth()->user()?->can('update', $cuenta) ?? false);
            })
            ->modalHeading('Registrar cobro del cliente')
            ->modalDescription(function (Proyecto $record): string {
                $cuenta = $record->cuentaPorCobrarPendiente();

                if ($cuenta === null) {
                    return '';
                }

                return "Se cobra contra {$cuenta->codigo} · saldo L "
                    .number_format((float) $cuenta->saldo, 2)
                    .' · vence el '.$cuenta->fecha_vencimiento->format('d/m/Y')
                    .'. Anticipos y abonos entran igual: bajan el saldo.';
            })
            ->modalSubmitActionLabel('Registrar cobro')
            ->schema(AccionCobrar::campos(
                static fn (mixed $record): ?CuentaPorCobrar => $record instanceof Proyecto
                    ? $record->cuentaPorCobrarPendiente()
                    : null,
            ))
            ->action(function (Proyecto $record, array $data): void {
                $cuenta = $record->cuentaPorCobrarPendiente();

                if ($cuenta === null) {
                    Notification::make()
                        ->warning()
                        ->title('Sin cuenta pendiente')
                        ->body('Este proyecto ya no tiene saldo por cobrar.')
                        ->send();

                    return;
                }

                if (! AccionCobrar::ejecutar($cuenta, $data)) {
                    return;
                }

                $cuenta->refresh();

                Notification::make()
                    ->success()
                    ->title('Cobro registrado')
                    ->body(bccomp((string) $cuenta->saldo, '0', 2) > 0
                        ? 'Saldo restante: L '.number_format((float) $cuenta->saldo, 2)
                            .' — vence el '.$cuenta->fecha_vencimiento->format('d/m/Y').'.'
                        : 'Cuenta saldada: el cliente no debe nada de este proyecto.')
                    ->send();
            });
    }
}
