<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Actions;

use App\Enums\EstadoAsignacion;
use App\Exceptions\Maquinaria\CombustibleInvalidoException;
use App\Models\AsignacionMaquina;
use App\Services\Maquinaria\RegistrarConsumoCombustibleService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Acción "Registrar combustible" sobre una asignación activa. Captura litros y
 * precio, y delega en RegistrarConsumoCombustibleService, que calcula el costo
 * y lo carga a la obra. Solo visible mientras la asignación está activa.
 */
final class AccionRegistrarCombustible
{
    public static function make(): Action
    {
        return Action::make('registrar_combustible')
            ->label('Registrar combustible')
            ->icon('heroicon-o-beaker')
            ->color('gray')
            ->modalHeading('Registrar combustible')
            ->modalDescription('El costo (litros × precio) se carga a la obra de esta asignación.')
            ->modalSubmitActionLabel('Registrar')
            ->visible(fn (AsignacionMaquina $record): bool => $record->estado === EstadoAsignacion::Activa)
            ->fillForm(fn (): array => [
                'fecha' => now()->toDateString(),
            ])
            ->schema([
                TextInput::make('cantidad_litros')
                    ->label('Cantidad de combustible')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->step('any')
                    ->suffix('L'),

                TextInput::make('precio_litro')
                    ->label('Precio por litro')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step('any')
                    ->prefix('L.'),

                DatePicker::make('fecha')
                    ->label('Fecha')
                    ->default(now())
                    ->required()
                    ->native(false)
                    ->maxDate(now()),

                TextInput::make('operador')
                    ->label('Operador')
                    ->maxLength(150),

                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(2),
            ])
            ->action(function (AsignacionMaquina $record, array $data): void {
                $userId = auth()->id();
                $userId = is_numeric($userId) ? (int) $userId : null;

                try {
                    app(RegistrarConsumoCombustibleService::class)->registrar(
                        asignacion: $record,
                        litros: (string) $data['cantidad_litros'],
                        precioLitro: (string) $data['precio_litro'],
                        fecha: isset($data['fecha']) ? (string) $data['fecha'] : null,
                        operador: $data['operador'] ?? null,
                        userId: $userId,
                        notas: $data['notas'] ?? null,
                    );
                } catch (CombustibleInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo registrar el combustible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Combustible registrado')
                    ->body('El costo se cargó a la obra.')
                    ->success()
                    ->send();
            });
    }
}
