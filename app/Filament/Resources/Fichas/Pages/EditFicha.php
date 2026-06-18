<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fichas\Pages;

use App\Filament\Resources\Fichas\FichaResource;
use App\Models\Ficha;
use App\Models\Zona;
use App\Services\Fichas\CalcularPrecioFichaService;
use App\Services\Fichas\DuplicarFichaAOtraZona;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFicha extends EditRecord
{
    protected static string $resource = FichaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            $this->actionRecalcular(),
            $this->actionDuplicarAZona(),
            DeleteAction::make(),
        ];
    }

    /**
     * Al guardar cambios en la ficha o sus líneas, recalcular el cache
     * de precio. Mantiene `subtotal_cache` y `precio_venta_cache`
     * sincronizados sin requerir acción manual del usuario.
     */
    protected function afterSave(): void
    {
        /** @var Ficha $ficha */
        $ficha = $this->record;

        $resultado = app(CalcularPrecioFichaService::class)
            ->recalcularYPersistir($ficha);

        Notification::make()
            ->success()
            ->title('Ficha guardada y recalculada')
            ->body("Precio venta: L {$resultado->precioVenta}")
            ->send();
    }

    private function actionRecalcular(): Action
    {
        return Action::make('recalcular')
            ->label('Recalcular precios')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->action(function (Ficha $record): void {
                $resultado = app(CalcularPrecioFichaService::class)
                    ->recalcularYPersistir($record);

                Notification::make()
                    ->success()
                    ->title('Cache de precios actualizado')
                    ->body("Subtotal: L {$resultado->subtotal} · Precio venta: L {$resultado->precioVenta}")
                    ->send();
            })
            ->visible(fn (Ficha $record): bool => $record->lineas()->exists());
    }

    /**
     * Acción "Duplicar a otra zona" — clona la ficha completa a una
     * zona destino, reutilizando items existentes y creando los
     * faltantes con precio 0 para revisión posterior.
     */
    private function actionDuplicarAZona(): Action
    {
        return Action::make('duplicar_a_zona')
            ->label('Duplicar a otra zona')
            ->icon('heroicon-o-document-duplicate')
            ->color('primary')
            ->modalHeading('Duplicar ficha a otra zona')
            ->modalDescription('Elige la zona destino. La ficha se clona con todas sus líneas. Los items que no existan en la zona destino se crearán con precio 0 para que los actualices después.')
            ->form([
                Select::make('zona_destino_id')
                    ->label('Zona destino')
                    ->required()
                    ->options(function (Ficha $record): array {
                        return Zona::activas()
                            ->where('id', '!=', $record->zona_id)
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->helperText('Solo aparecen zonas activas distintas a la zona actual de la ficha.'),
            ])
            ->action(function (Ficha $record, array $data): void {
                /** @var Zona $zonaDestino */
                $zonaDestino = Zona::findOrFail($data['zona_destino_id']);

                $resultado = app(DuplicarFichaAOtraZona::class)
                    ->ejecutar($record, $zonaDestino);

                /** @var Ficha $fichaNueva */
                $fichaNueva = $resultado['ficha_destino'];

                $body = sprintf(
                    'Nueva ficha: %s · %d items reutilizados, %d items creados con precio 0%s',
                    $fichaNueva->codigo,
                    $resultado['items_reutilizados'],
                    $resultado['items_creados'],
                    $resultado['items_creados'] > 0 ? ' (revísalos en Base de precios).' : '.'
                );

                Notification::make()
                    ->success()
                    ->title("Ficha duplicada a {$zonaDestino->codigo}")
                    ->body($body)
                    ->actions([
                        Action::make('ver_destino')
                            ->label('Abrir ficha duplicada')
                            ->url(FichaResource::getUrl('edit', ['record' => $fichaNueva]))
                            ->button(),
                    ])
                    ->persistent()
                    ->send();
            })
            ->visible(fn (Ficha $record): bool => $record->lineas()->exists());
    }
}
