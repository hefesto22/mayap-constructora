<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos\Actions;

use App\Enums\EstadoMantenimiento;
use App\Enums\FaseMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\MantenimientoMaquina;
use App\Services\Maquinaria\RegistrarAvanceMantenimientoService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Acción "Registrar avance": el diagnóstico o cambio de fase de una
 * reparación en proceso. Deja SIEMPRE una entrada en la bitácora con
 * fecha, hora y quién lo registró (RegistrarAvanceMantenimientoService,
 * la única puerta). Se usa igual desde la tabla y la página de vista.
 */
final class AccionRegistrarAvance
{
    public static function make(): Action
    {
        return Action::make('registrar_avance')
            ->label('Registrar avance')
            ->icon('heroicon-o-clipboard-document-list')
            ->color('primary')
            ->modalHeading(fn (MantenimientoMaquina $record): string => 'Registrar avance · '.$record->codigo)
            ->modalDescription('Anota el diagnóstico o avance y, si cambió, la fase de la reparación. Queda en el historial con fecha y hora.')
            ->modalSubmitActionLabel('Registrar')
            ->visible(fn (MantenimientoMaquina $record): bool => $record->estado === EstadoMantenimiento::EnProceso
                && (auth()->user()?->can('update', $record) ?? false))
            ->fillForm(fn (MantenimientoMaquina $record): array => [
                'fase'                     => $record->fase->value,
                'fecha_estimada_repuestos' => $record->fecha_estimada_repuestos?->toDateString(),
            ])
            ->schema([
                Select::make('fase')
                    ->label('Fase de la reparación')
                    ->options(FaseMantenimiento::options())
                    ->required()
                    ->live()
                    ->native(false)
                    ->helperText('Puede quedarse en la misma fase: la entrada igual se guarda en el historial.'),

                Textarea::make('detalle')
                    ->label('Diagnóstico / detalle del avance')
                    ->required()
                    ->rows(3)
                    ->mayusculas()
                    ->placeholder('SE ENCONTRÓ FUGA EN LA BOMBA HIDRÁULICA...'),

                DatePicker::make('fecha_estimada_repuestos')
                    ->label('Fecha estimada de recepción de repuestos')
                    ->native(false)
                    ->minDate(now())
                    ->visible(fn (Get $get): bool => in_array($get('fase'), [
                        FaseMantenimiento::SinRepuestos->value,
                        FaseMantenimiento::CompraRepuestos->value,
                    ], true))
                    ->required(fn (Get $get): bool => $get('fase') === FaseMantenimiento::CompraRepuestos->value)
                    ->helperText('Ese día llegará la campanita "repuestos por llegar". Cambiarla reinicia el aviso.'),
            ])
            ->action(function (MantenimientoMaquina $record, array $data): void {
                $userId = auth()->id();
                $userId = is_numeric($userId) ? (int) $userId : null;

                try {
                    app(RegistrarAvanceMantenimientoService::class)->avanzar(
                        mantenimiento: $record,
                        fase: FaseMantenimiento::from((string) $data['fase']),
                        detalle: (string) $data['detalle'],
                        fechaEstimadaRepuestos: isset($data['fecha_estimada_repuestos'])
                            ? (string) $data['fecha_estimada_repuestos']
                            : null,
                        userId: $userId,
                    );
                } catch (MantenimientoInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo registrar el avance')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Avance registrado')
                    ->body('Quedó en el historial del mantenimiento con fecha y hora.')
                    ->success()
                    ->send();
            });
    }
}
