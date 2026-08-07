<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Actions;

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraException;
use App\Models\Compra;
use App\Models\User;
use App\Services\Compras\ReprogramarLlegadaCompraService;
use App\Support\Permisos;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * "El proveedor movió la entrega" (decisión Mauricio 2026-08-07).
 *
 * Antes la fecha estimada de llegada solo se podía tocar en el borrador:
 * una vez registrado el pedido quedaba congelada, y cuando el proveedor
 * reprogramaba nadie se enteraba. La obra seguía esperando material que ya
 * no venía ese día.
 *
 * Mismo patrón que "Reprogramar" de requisiciones: motivo OBLIGATORIO,
 * rastro en la bitácora con anterior → nueva, y aviso inmediato a la obra
 * (campanita + WhatsApp) diciendo si se adelantó o se ATRASÓ.
 */
final class AccionReprogramarLlegada
{
    public static function make(): Action
    {
        return Action::make('reprogramar_llegada')
            ->label('Reprogramar llegada')
            ->icon('heroicon-o-calendar-days')
            ->color('warning')
            ->visible(fn (Compra $record): bool => $record->estado === EstadoCompra::PorRecibir
                && self::puede())
            ->modalHeading('Reprogramar la llegada del pedido')
            ->modalDescription(fn (Compra $record): string => $record->fecha_estimada_llegada !== null
                ? 'El proveedor prometió el '.$record->fecha_estimada_llegada->format('d/m/Y')
                    .'. Indicá la nueva fecha y el motivo: la obra que espera este material se entera al instante.'
                : 'Este pedido no tenía fecha de llegada. Indicá cuándo llega y por qué.')
            ->modalSubmitActionLabel('Reprogramar')
            ->fillForm(fn (Compra $record): array => [
                'fecha_estimada_llegada' => $record->fecha_estimada_llegada?->toDateString(),
            ])
            ->schema([
                DatePicker::make('fecha_estimada_llegada')
                    ->label('Nueva fecha de llegada')
                    ->required()
                    ->native(false)
                    ->minDate(today()),
                Textarea::make('motivo')
                    ->label('Motivo del cambio')
                    ->required()
                    ->rows(3)
                    ->placeholder('¿Qué dijo el proveedor? Queda en la bitácora de la requisición.'),
            ])
            ->action(function (Compra $record, array $data): void {
                try {
                    app(ReprogramarLlegadaCompraService::class)->reprogramar(
                        $record,
                        Carbon::parse((string) $data['fecha_estimada_llegada']),
                        (string) $data['motivo'],
                        self::userId(),
                    );
                } catch (CompraException $e) {
                    Notification::make()
                        ->title('No se pudo reprogramar')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Llegada reprogramada')
                    ->body('La obra que espera este material ya fue avisada.')
                    ->success()
                    ->send();
            });
    }

    private static function puede(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can(Permisos::REALIZAR_COMPRA_REQUISICION);
    }

    private static function userId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}
