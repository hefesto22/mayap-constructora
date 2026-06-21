<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Actions;

use App\Enums\EstadoAsignacion;
use App\Enums\MetodoCapturaHoras;
use App\Exceptions\Maquinaria\ParteInvalidoException;
use App\Models\AsignacionMaquina;
use App\Services\Maquinaria\RegistrarParteService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Acción "Registrar parte" sobre una asignación activa. Captura horas por
 * horómetro (lecturas) o manual (horas directas) y delega en
 * RegistrarParteService, que calcula horas extra y el costo. El motivo de
 * horas extra lo valida el Service (notifica si falta).
 */
final class AccionRegistrarParte
{
    public static function make(): Action
    {
        return Action::make('registrar_parte')
            ->label('Registrar parte')
            ->icon('heroicon-o-clock')
            ->color('primary')
            ->modalHeading('Registrar parte de trabajo')
            ->modalDescription('Captura las horas trabajadas. Por horómetro (recomendado) o manual si el reloj falla.')
            ->modalSubmitActionLabel('Registrar parte')
            ->visible(fn (AsignacionMaquina $record): bool => $record->estado === EstadoAsignacion::Activa)
            ->fillForm(fn (AsignacionMaquina $record): array => [
                'metodo_captura'  => MetodoCapturaHoras::Horometro->value,
                'fecha'           => now()->toDateString(),
                'lectura_inicial' => (string) $record->maquina->horometro_actual,
            ])
            ->schema([
                Select::make('metodo_captura')
                    ->label('Método de captura')
                    ->options(MetodoCapturaHoras::options())
                    ->default(MetodoCapturaHoras::Horometro->value)
                    ->required()
                    ->live()
                    ->native(false),

                TextInput::make('lectura_inicial')
                    ->label('Lectura inicial del horómetro')
                    ->numeric()
                    ->step('any')
                    ->suffix('h')
                    ->visible(fn (Get $get): bool => $get('metodo_captura') === MetodoCapturaHoras::Horometro->value)
                    ->helperText('Por defecto, el horómetro actual de la máquina.'),

                TextInput::make('lectura_final')
                    ->label('Lectura final del horómetro')
                    ->numeric()
                    ->step('any')
                    ->suffix('h')
                    ->required(fn (Get $get): bool => $get('metodo_captura') === MetodoCapturaHoras::Horometro->value)
                    ->visible(fn (Get $get): bool => $get('metodo_captura') === MetodoCapturaHoras::Horometro->value),

                TextInput::make('horas')
                    ->label('Horas trabajadas')
                    ->numeric()
                    ->step('any')
                    ->minValue(0.01)
                    ->suffix('h')
                    ->required(fn (Get $get): bool => $get('metodo_captura') === MetodoCapturaHoras::Manual->value)
                    ->visible(fn (Get $get): bool => $get('metodo_captura') === MetodoCapturaHoras::Manual->value),

                DatePicker::make('fecha')
                    ->label('Fecha')
                    ->default(now())
                    ->required()
                    ->native(false)
                    ->maxDate(now()),

                TextInput::make('operador')
                    ->label('Operador')
                    ->maxLength(150)
                    ->placeholder('Nombre del operador'),

                Textarea::make('motivo_horas_extra')
                    ->label('Motivo de horas extra')
                    ->rows(2)
                    ->helperText('Obligatorio solo si las horas superan la jornada estándar de la máquina.'),

                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(2),
            ])
            ->action(function (AsignacionMaquina $record, array $data): void {
                $userId = auth()->id();
                $userId = is_numeric($userId) ? (int) $userId : null;

                $service = app(RegistrarParteService::class);
                $metodo = $data['metodo_captura'];

                try {
                    if ($metodo === MetodoCapturaHoras::Horometro->value) {
                        $service->registrarPorHorometro(
                            asignacion: $record,
                            lecturaFinal: (string) $data['lectura_final'],
                            lecturaInicial: isset($data['lectura_inicial'])
                                ? (string) $data['lectura_inicial']
                                : null,
                            fecha: isset($data['fecha']) ? (string) $data['fecha'] : null,
                            motivoHorasExtra: $data['motivo_horas_extra'] ?? null,
                            operador: $data['operador'] ?? null,
                            userId: $userId,
                            notas: $data['notas'] ?? null,
                        );
                    } else {
                        $service->registrarManual(
                            asignacion: $record,
                            horas: (string) $data['horas'],
                            fecha: isset($data['fecha']) ? (string) $data['fecha'] : null,
                            motivoHorasExtra: $data['motivo_horas_extra'] ?? null,
                            operador: $data['operador'] ?? null,
                            userId: $userId,
                            notas: $data['notas'] ?? null,
                        );
                    }
                } catch (ParteInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo registrar el parte')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Parte registrado')
                    ->body('Las horas y el costo se cargaron a la obra.')
                    ->success()
                    ->send();
            });
    }
}
