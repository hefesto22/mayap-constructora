<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos\Actions;

use App\Enums\EstadoMantenimiento;
use App\Enums\PrioridadMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\MantenimientoMaquina;
use App\Services\Maquinaria\CambiarPrioridadMantenimientoService;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Acción "Prioridad": gerencia o recepción marcan qué reparación es la
 * más importante (decisión Mauricio 2026-07-20). El cambio avisa por
 * campanita al taller y queda en la bitácora con fecha, hora y quién.
 */
final class AccionCambiarPrioridad
{
    public static function make(): Action
    {
        return Action::make('cambiar_prioridad')
            ->label('Prioridad')
            ->icon('heroicon-o-fire')
            ->color(fn (MantenimientoMaquina $record): string => $record->prioridad->getColor())
            ->modalHeading(fn (MantenimientoMaquina $record): string => 'Prioridad de reparación · '.$record->codigo)
            ->modalDescription('Marca qué tan importante es reparar esta máquina. El taller recibe la campanita y el cambio queda en el historial.')
            ->modalSubmitActionLabel('Guardar prioridad')
            // Solo la oficina decide el orden del taller (gerencia /
            // recepción — mismo criterio que Roles::compra).
            ->visible(fn (MantenimientoMaquina $record): bool => $record->estado === EstadoMantenimiento::EnProceso
                && Roles::compra(auth()->user()))
            ->fillForm(fn (MantenimientoMaquina $record): array => [
                'prioridad' => $record->prioridad->value,
            ])
            ->schema([
                Select::make('prioridad')
                    ->label('Prioridad')
                    ->options(PrioridadMantenimiento::options())
                    ->required()
                    ->native(false)
                    ->helperText('URGENTE = la más importante del taller; el aviso lo deja claro.'),

                Textarea::make('motivo')
                    ->label('Motivo (opcional)')
                    ->rows(2)
                    ->mayusculas()
                    ->placeholder('LA OBRA X ESTÁ PARADA SIN ESTA MÁQUINA'),
            ])
            ->action(function (MantenimientoMaquina $record, array $data): void {
                $userId = auth()->id();
                $userId = is_numeric($userId) ? (int) $userId : null;

                try {
                    app(CambiarPrioridadMantenimientoService::class)->cambiar(
                        mantenimiento: $record,
                        prioridad: PrioridadMantenimiento::from((string) $data['prioridad']),
                        motivo: isset($data['motivo']) ? (string) $data['motivo'] : null,
                        userId: $userId,
                    );
                } catch (MantenimientoInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo cambiar la prioridad')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Prioridad guardada')
                    ->body('El taller ya tiene el aviso y quedó en el historial.')
                    ->success()
                    ->send();

                // Refresca la instancia que muestra el infolist de Ver:
                // sin esto la prioridad se ve vieja hasta recargar.
                $record->refresh();
            });
    }
}
