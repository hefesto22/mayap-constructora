<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Actions;

use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\Maquina;
use App\Services\Maquinaria\MantenimientoService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Acción "Enviar a mantenimiento" sobre una máquina operativa. Captura el
 * motivo y, opcionalmente, una máquina sustituta para la misma obra, y delega
 * en MantenimientoService (finaliza la asignación activa, deja la máquina en
 * mantenimiento y registra la sustitución).
 */
final class AccionEnviarAMantenimiento
{
    public static function make(): Action
    {
        return Action::make('enviar_mantenimiento')
            ->label('Enviar a mantenimiento')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('warning')
            ->modalHeading('Enviar máquina a mantenimiento')
            ->modalDescription('Si la máquina está trabajando, se libera de su obra. Puedes asignar una máquina sustituta a esa obra.')
            ->modalSubmitActionLabel('Enviar a mantenimiento')
            ->visible(fn (Maquina $record): bool => in_array(
                $record->estado,
                [EstadoMaquina::Disponible, EstadoMaquina::Asignada],
                strict: true,
            ))
            ->schema([
                Textarea::make('motivo')
                    ->label('Motivo de la avería / mantenimiento')
                    ->required()
                    ->rows(2)
                    ->placeholder('FALLA HIDRÁULICA, MANTENIMIENTO PREVENTIVO, ...'),

                Select::make('sustituta_id')
                    ->label('Máquina sustituta (opcional)')
                    ->options(fn (Maquina $record): array => Maquina::query()
                        ->activas()
                        ->disponibles()
                        ->whereKeyNot($record->getKey())
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (Maquina $m): array => [
                            $m->id => "{$m->codigo} — {$m->nombre}",
                        ])
                        ->all())
                    ->searchable()
                    ->native(false)
                    ->helperText('Solo aplica si esta máquina está asignada a una obra. La sustituta toma esa obra.'),

                DatePicker::make('fecha')
                    ->label('Fecha')
                    ->default(now())
                    ->required()
                    ->native(false)
                    ->maxDate(now()),

                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(2),
            ])
            ->action(function (Maquina $record, array $data): void {
                $sustituta = null;

                if (! empty($data['sustituta_id'])) {
                    $sustituta = Maquina::query()->find((int) $data['sustituta_id']);
                }

                try {
                    app(MantenimientoService::class)->enviarAMantenimiento(
                        maquina: $record,
                        motivo: (string) $data['motivo'],
                        sustituta: $sustituta,
                        fecha: isset($data['fecha']) ? (string) $data['fecha'] : null,
                        notas: $data['notas'] ?? null,
                    );
                } catch (MantenimientoInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo enviar a mantenimiento')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Máquina en mantenimiento')
                    ->body('La máquina quedó fuera de servicio.')
                    ->success()
                    ->send();
            });
    }
}
