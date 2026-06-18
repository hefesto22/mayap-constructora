<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Enums\EstadoProyecto;
use App\Exceptions\Proyectos\ProyectoNoEditableException;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use App\Services\Proyectos\ActualizarPreciosProyectoService;
use App\Services\Proyectos\CalcularPrecioProyectoService;
use App\Services\Proyectos\DuplicarProyectoService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProyecto extends EditRecord
{
    protected static string $resource = ProyectoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            $this->actionRecalcular(),
            $this->actionActualizarPrecios(),
            $this->actionCambiarEstado(),
            $this->actionDuplicar(),
            DeleteAction::make()
                ->visible(fn (Proyecto $record): bool => $record->estado === EstadoProyecto::Borrador),
        ];
    }

    /**
     * Al guardar cambios en el proyecto o sus renglones, recalcular el
     * cache de totales para mantener subtotal/ISV/total sincronizados.
     */
    protected function afterSave(): void
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->record;

        $resultado = app(CalcularPrecioProyectoService::class)
            ->recalcular($proyecto);

        Notification::make()
            ->success()
            ->title('Proyecto guardado y recalculado')
            ->body("Total: L {$resultado->total_cache}")
            ->send();
    }

    /**
     * Acción "Recalcular precios" — vuelve a calcular subtotal, ISV y
     * total. Útil después de cambios manuales en cantidades de renglones.
     */
    private function actionRecalcular(): Action
    {
        return Action::make('recalcular')
            ->label('Recalcular')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->action(function (Proyecto $record): void {
                $resultado = app(CalcularPrecioProyectoService::class)
                    ->recalcular($record);

                Notification::make()
                    ->success()
                    ->title('Totales actualizados')
                    ->body("Subtotal: L {$resultado->subtotal_cache} · Total: L {$resultado->total_cache}")
                    ->send();
            })
            ->visible(fn (Proyecto $record): bool => $record->renglones()->exists());
    }

    /**
     * Acción "Actualizar precios a actuales" — refresca todos los
     * snapshots del proyecto con los precios vigentes de las fichas.
     * Solo disponible en estado Borrador.
     */
    private function actionActualizarPrecios(): Action
    {
        return Action::make('actualizar_precios')
            ->label('Actualizar precios')
            ->icon('heroicon-o-arrow-up-circle')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Actualizar precios al valor actual de las fichas')
            ->modalDescription('Los snapshots de los renglones se actualizarán a los precios actuales de las fichas. Esta acción NO se puede deshacer (los precios anteriores se pierden).')
            ->modalSubmitActionLabel('Sí, actualizar precios')
            ->action(function (Proyecto $record): void {
                try {
                    $resultado = app(ActualizarPreciosProyectoService::class)
                        ->ejecutar($record);

                    Notification::make()
                        ->success()
                        ->title('Precios actualizados')
                        ->body(sprintf(
                            '%d renglones actualizados. Total: L %s → L %s (Δ %s)',
                            $resultado['renglones_actualizados'],
                            $resultado['total_anterior'],
                            $resultado['total_nuevo'],
                            $resultado['diferencia']
                        ))
                        ->persistent()
                        ->send();
                } catch (ProyectoNoEditableException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se puede actualizar')
                        ->body($e->getMessage())
                        ->send();
                }
            })
            ->visible(
                fn (Proyecto $record): bool => $record->estado->permiteEditar()
                && $record->renglones()->exists()
            );
    }

    /**
     * Acción "Cambiar estado" — workflow controlado del estado.
     * Solo permite las transiciones definidas en el enum (no acepta
     * saltos arbitrarios como Borrador → Aprobada).
     */
    private function actionCambiarEstado(): Action
    {
        return Action::make('cambiar_estado')
            ->label('Cambiar estado')
            ->icon('heroicon-o-flag')
            ->color('primary')
            ->modalHeading('Cambiar estado del proyecto')
            ->modalDescription(fn (Proyecto $record): string => 'Estado actual: '.$record->estado->getLabel())
            ->schema([
                Select::make('nuevo_estado')
                    ->label('Nuevo estado')
                    ->required()
                    ->options(function (Proyecto $record): array {
                        $opciones = [];

                        foreach ($record->estado->transicionesPermitidas() as $estado) {
                            $opciones[$estado->value] = $estado->getLabel();
                        }

                        return $opciones;
                    })
                    ->native(false),

                Textarea::make('razon')
                    ->label('Razón (opcional)')
                    ->rows(3)
                    ->placeholder('Notas sobre el cambio de estado'),
            ])
            ->action(function (Proyecto $record, array $data): void {
                $nuevoEstado = EstadoProyecto::from($data['nuevo_estado']);
                $estadoAnterior = $record->estado;

                $record->update(['estado' => $nuevoEstado->value]);

                activity('cambio_estado_proyecto')
                    ->performedOn($record)
                    ->withProperties([
                        'estado_anterior' => $estadoAnterior->value,
                        'estado_nuevo'    => $nuevoEstado->value,
                        'razon'           => $data['razon'] ?? null,
                    ])
                    ->event('estado_cambiado')
                    ->log("Proyecto {$record->codigo}: {$estadoAnterior->getLabel()} → {$nuevoEstado->getLabel()}");

                Notification::make()
                    ->success()
                    ->title('Estado actualizado')
                    ->body("Nuevo estado: {$nuevoEstado->getLabel()}")
                    ->send();
            })
            ->visible(fn (Proyecto $record): bool => count($record->estado->transicionesPermitidas()) > 0);
    }

    /**
     * Acción "Duplicar" — crea una nueva cotización a partir del
     * proyecto actual con precios actualizados, en estado Borrador.
     */
    private function actionDuplicar(): Action
    {
        return Action::make('duplicar')
            ->label('Duplicar')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Duplicar proyecto')
            ->modalDescription('Se creará un nuevo proyecto Borrador con los mismos datos del cliente, zona y dirección, pero con código nuevo, fechas reiniciadas y precios actualizados al valor actual de las fichas.')
            ->modalSubmitActionLabel('Sí, duplicar')
            ->action(function (Proyecto $record): void {
                $resultado = app(DuplicarProyectoService::class)
                    ->ejecutar($record);

                /** @var Proyecto $proyectoNuevo */
                $proyectoNuevo = $resultado['proyecto_destino'];

                Notification::make()
                    ->success()
                    ->title("Proyecto duplicado: {$proyectoNuevo->codigo}")
                    ->body(sprintf(
                        '%d renglones copiados · Total: L %s',
                        $resultado['renglones_copiados'],
                        $resultado['total_destino']
                    ))
                    ->actions([
                        Action::make('abrir_duplicado')
                            ->label('Abrir cotización nueva')
                            ->url(ProyectoResource::getUrl('edit', ['record' => $proyectoNuevo]))
                            ->button(),
                    ])
                    ->persistent()
                    ->send();
            });
    }
}
