<?php

declare(strict_types=1);

namespace App\Filament\Resources\AsignacionesMaquina\Actions;

use App\Exceptions\Maquinaria\AsignacionInvalidaException;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Maquinaria\AsignarMaquinaService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Acción "Asignar máquina a obra". Construye el modal de captura (máquina
 * disponible, obra, tarifa pactada, fecha) y delega en AsignarMaquinaService,
 * que es la única puerta que vincula máquina↔obra y mueve el estado de la
 * máquina. Al elegir la máquina, prellena su tarifa por defecto.
 */
final class AccionAsignar
{
    public static function make(): Action
    {
        return Action::make('asignar')
            ->label('Asignar máquina')
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->modalHeading('Asignar máquina a una obra')
            ->modalDescription('Solo se listan máquinas disponibles. La tarifa se hereda de la máquina y puedes ajustarla para esta obra.')
            ->modalSubmitActionLabel('Asignar')
            ->schema([
                Select::make('maquina_id')
                    ->label('Máquina disponible')
                    ->options(fn (): array => Maquina::query()
                        ->activas()
                        ->disponibles()
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (Maquina $m): array => [
                            $m->id => "{$m->codigo} — {$m->nombre}",
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        if ($state === null) {
                            return;
                        }

                        $maquina = Maquina::query()->find($state);

                        if ($maquina instanceof Maquina) {
                            $set('tarifa_hora_pactada', (string) $maquina->tarifa_hora);
                        }
                    })
                    ->helperText('Si no aparece una máquina, revisa que esté activa y disponible.'),

                Select::make('proyecto_id')
                    ->label('Obra')
                    ->options(fn (): array => Proyecto::query()
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (Proyecto $p): array => [
                            $p->id => "{$p->codigo} — {$p->nombre}",
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false),

                TextInput::make('tarifa_hora_pactada')
                    ->label('Tarifa por hora pactada')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step('any')
                    ->prefix('L.')
                    ->helperText('Se cobra a la obra por cada hora trabajada de esta máquina.'),

                DatePicker::make('fecha_inicio')
                    ->label('Fecha de inicio')
                    ->default(now())
                    ->required()
                    ->native(false),

                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $maquina = Maquina::query()->find($data['maquina_id']);

                if (! $maquina instanceof Maquina) {
                    Notification::make()->title('Máquina no encontrada')->danger()->send();

                    return;
                }

                try {
                    app(AsignarMaquinaService::class)->asignar(
                        maquina: $maquina,
                        proyectoId: (int) $data['proyecto_id'],
                        tarifaPactada: (string) $data['tarifa_hora_pactada'],
                        fechaInicio: isset($data['fecha_inicio']) ? (string) $data['fecha_inicio'] : null,
                        notas: $data['notas'] ?? null,
                    );
                } catch (AsignacionInvalidaException $e) {
                    Notification::make()
                        ->title('No se pudo asignar')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Máquina asignada')
                    ->body('La máquina quedó asignada a la obra y lista para registrar partes.')
                    ->success()
                    ->send();
            });
    }
}
