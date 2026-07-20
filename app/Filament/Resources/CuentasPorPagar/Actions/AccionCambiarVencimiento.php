<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar\Actions;

use App\Enums\EstadoCuentaPorPagar;
use App\Models\CuentaPorPagar;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

/**
 * Cambiar la fecha de vencimiento de una cuenta por pagar (decisión
 * Mauricio 2026-07-20): la fecha nace sola de los días de crédito del
 * proveedor al confirmar la compra, pero la factura real puede traer
 * otro plazo — aquí se ajusta sin tocar la compra.
 *
 * Al cambiarla se REINICIA el escalón de avisos (ultimo_aviso_dias =
 * NULL): la nueva fecha rearma las campanitas de 7/3/0 días.
 */
final class AccionCambiarVencimiento
{
    public static function make(): Action
    {
        return Action::make('cambiar_vencimiento')
            ->label('Vencimiento')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (CuentaPorPagar $record): bool => $record->estado !== EstadoCuentaPorPagar::Pagada
                && (auth()->user()?->can('update', $record) ?? false))
            ->modalHeading('Cambiar fecha de vencimiento')
            ->modalDescription('Ajusta la fecha máxima de pago según la factura del proveedor. Los avisos de vencimiento se reinician con la nueva fecha.')
            ->modalSubmitActionLabel('Guardar fecha')
            ->fillForm(fn (CuentaPorPagar $record): array => [
                'fecha_vencimiento' => $record->fecha_vencimiento->toDateString(),
            ])
            ->schema([
                DatePicker::make('fecha_vencimiento')
                    ->label('Nueva fecha de vencimiento')
                    ->required()
                    ->native(false)
                    ->minDate(fn (CuentaPorPagar $record) => $record->fecha_emision)
                    ->helperText('No puede ser anterior a la emisión de la cuenta.'),
            ])
            ->action(function (CuentaPorPagar $record, array $data): void {
                $anterior = $record->fecha_vencimiento->format('d/m/Y');

                $record->forceFill([
                    'fecha_vencimiento' => (string) $data['fecha_vencimiento'],
                    'ultimo_aviso_dias' => null,
                ])->save();

                activity('compras')
                    ->performedOn($record)
                    ->withProperties([
                        'anterior' => $anterior,
                        'nueva'    => $record->fecha_vencimiento->format('d/m/Y'),
                    ])
                    ->event('vencimiento_cambiado')
                    ->log("Vencimiento de la cuenta por pagar #{$record->id} cambiado");

                Notification::make()
                    ->title('Fecha de vencimiento actualizada')
                    ->body('Los avisos de pago se reiniciaron con la nueva fecha.')
                    ->success()
                    ->send();
            });
    }
}
