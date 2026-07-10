<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Enums\EstadoProyecto;
use App\Exceptions\Proyectos\ProyectoNoEditableException;
use App\Filament\Resources\Proyectos\Actions\AccionesEjecucion;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use App\Services\Proyectos\ActualizarPreciosProyectoService;
use App\Services\Proyectos\CalcularPrecioProyectoService;
use App\Services\Proyectos\DuplicarProyectoService;
use App\Services\Proyectos\TransicionComercialProyectoService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
            ViewAction::make()
                ->label('Ver')
                ->color('gray'),
            ActionGroup::make([
                // Estado comercial
                $this->actionCambiarEstado(),
                $this->actionVolverABorrador(),
                // Ejecución de obra
                AccionesEjecucion::iniciar(),
                AccionesEjecucion::registrarAnticipo(),
                AccionesEjecucion::ajustarPlazo(),
                AccionesEjecucion::pausar(),
                AccionesEjecucion::reactivar(),
                AccionesEjecucion::finalizar(),
                AccionesEjecucion::cancelar(),
                // Herramientas
                $this->actionRecalcular(),
                $this->actionActualizarPrecios(),
                $this->actionDuplicar(),
                DeleteAction::make()
                    ->visible(fn (Proyecto $record): bool => $record->estado === EstadoProyecto::Borrador),
            ])
                ->label('Acciones')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button(),
        ];
    }

    /**
     * Recalcular totales SOLO si cambió algo que los afecte (configuración
     * de ISV). Guardar campos de cabecera (encargado, fechas, notas) no
     * toca números — los renglones ya recalculan por su propio flujo.
     */
    protected function afterSave(): void
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->record;

        if (! $proyecto->wasChanged(['aplica_isv', 'isv_porcentaje'])) {
            return; // Filament ya muestra su notificación "Guardado".
        }

        $resultado = app(CalcularPrecioProyectoService::class)
            ->recalcular($proyecto);

        Notification::make()
            ->success()
            ->title('ISV actualizado — totales recalculados')
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
            // Solo en Borrador: después de aprobar, el precio es contractual
            // y queda congelado (los cambios van por orden de cambio).
            ->visible(
                fn (Proyecto $record): bool => $record->estado === EstadoProyecto::Borrador
                && $record->renglones()->exists()
            );
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

                        foreach ($record->estado->transicionesSimples() as $estado) {
                            // "Volver a borrador" tiene su propia acción dedicada.
                            if ($estado === EstadoProyecto::Borrador) {
                                continue;
                            }

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

                app(TransicionComercialProyectoService::class)->cambiar(
                    $record,
                    $nuevoEstado,
                    $data['razon'] ?? null,
                );

                Notification::make()
                    ->success()
                    ->title('Estado actualizado')
                    ->body("Nuevo estado: {$nuevoEstado->getLabel()}")
                    ->send();
            })
            ->visible(fn (Proyecto $record): bool => count(array_filter(
                $record->estado->transicionesSimples(),
                fn (EstadoProyecto $estado): bool => $estado !== EstadoProyecto::Borrador,
            )) > 0);
    }

    /**
     * Acción "Volver a borrador" — desbloquea una cotización Enviada para
     * corregir un error en los renglones. Después se vuelve a marcar como
     * Enviada. El cambio queda registrado en el log de actividad.
     */
    private function actionVolverABorrador(): Action
    {
        return Action::make('volver_a_borrador')
            ->label('Volver a borrador')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (Proyecto $record): bool => $record->estado === EstadoProyecto::Enviada)
            ->requiresConfirmation()
            ->modalHeading('Volver la cotización a borrador')
            ->modalDescription('Se desbloqueará para corregir los renglones. Después podés volver a marcarla como Enviada. El cambio queda registrado.')
            ->modalSubmitActionLabel('Sí, volver a borrador')
            ->schema([
                Textarea::make('razon')
                    ->label('Motivo de la corrección (opcional)')
                    ->rows(2)
                    ->placeholder('EJ: SE EQUIVOCÓ LA CANTIDAD DE UNA FICHA'),
            ])
            ->action(function (Proyecto $record, array $data): void {
                app(TransicionComercialProyectoService::class)->volverABorrador(
                    $record,
                    $data['razon'] ?? null,
                );

                Notification::make()
                    ->success()
                    ->title('Cotización en borrador')
                    ->body('Ya podés corregir los renglones y luego volver a enviarla.')
                    ->send();
            });
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
