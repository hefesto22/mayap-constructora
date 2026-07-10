<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fichas\Tables;

use App\Filament\Resources\Fichas\FichaResource;
use App\Models\Ficha;
use App\Models\Zona;
use App\Services\Fichas\CalcularPrecioFichaService;
use App\Services\Fichas\DuplicarFichaAOtraZona;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tabla de fichas APU — listado principal del Sprint 2.
 *
 * Patrones clave:
 * - Filtros persistidos en sesión (Filament v4): el ingeniero entra,
 *   filtra por zona SRC, sale, vuelve mañana, sigue en SRC.
 * - Búsqueda combinada: código + nombre.
 * - Acción individual "Recalcular" por fila (visible siempre que la
 *   ficha tenga líneas). Bulk action "Recalcular seleccionadas" para
 *   operación masiva.
 * - Delete con confirmación (las fichas pueden estar referenciadas en
 *   presupuestos en Sprint 3, por ahora delete simple).
 */
class FichasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->copyable()
                    ->copyMessage('Código copiado')
                    ->description(fn (Ficha $record): string => $record->zona->nombre),

                TextColumn::make('nombre')
                    ->label('Actividad')
                    ->searchable()
                    ->sortable()
                    ->lineClamp(2)
                    ->grow()
                    ->tooltip(fn (Ficha $record): ?string => mb_strlen((string) $record->nombre) > 70 ? $record->nombre : null)
                    ->description(fn (Ficha $record): string => $record->unidadMedida->codigo.' · '.$record->lineas_count.' líneas'),

                TextColumn::make('unidadMedida.codigo')
                    ->label('Unidad')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('lineas_count')
                    ->label('Líneas')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('utilidad_porcentaje')
                    ->label('Utilidad')
                    ->suffix(' %')
                    ->numeric(2)
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('subtotal_cache')
                    ->label('Subtotal')
                    ->prefix('L ')
                    ->numeric(2)
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('precio_venta_cache')
                    ->label('Precio venta')
                    ->prefix('L ')
                    ->numeric(2)
                    ->sortable()
                    ->alignEnd()
                    ->weight(FontWeight::Bold)
                    ->size(TextSize::Large)
                    ->color('success'),

                IconColumn::make('cache_stale_indicator')
                    ->label('Cache')
                    ->state(fn (Ficha $record): bool => self::tieneCacheDesactualizado($record))
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->tooltip(fn (Ficha $record): string => self::tieneCacheDesactualizado($record)
                        ? 'Hay items con precios más nuevos que el cache. Recalcula esta ficha.'
                        : 'Cache al día con los precios actuales.')
                    ->alignCenter(),

                TextColumn::make('precio_calculado_at')
                    ->label('Cache actualizado')
                    ->since()
                    ->sortable()
                    ->placeholder('Sin recalcular')
                    ->tooltip(fn ($record): ?string => $record->precio_calculado_at?->format('d/m/Y H:i'))
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('activa')
                    ->label('Activa')
                    ->onColor('success')
                    ->offColor('danger'),

                TextColumn::make('updated_at')
                    ->label('Modificada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->striped()
            ->defaultSort('codigo', 'asc')
            ->persistFiltersInSession()
            ->filters([
                SelectFilter::make('zona_id')
                    ->label('Zona')
                    ->options(fn (): array => Zona::activas()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->preload()
                    ->searchable(),

                TernaryFilter::make('activa')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),

                Filter::make('cache_desactualizado')
                    ->label('Cache desactualizado')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->cacheDesactualizado())
                    ->indicator('Solo fichas con precios stale'),
            ])
            ->recordActions([
                Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (Ficha $record): string => $record->codigo)
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (Ficha $record): View => view(
                        'filament.fichas.hoja-apu',
                        [
                            'ficha' => $record,
                            'r'     => app(CalcularPrecioFichaService::class)->calcular($record),
                        ],
                    )),
                EditAction::make(),
                Action::make('duplicar')
                    ->label('Duplicar')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('info')
                    ->modalHeading('Duplicar ficha')
                    ->modalDescription(fn (Ficha $record): string => "Crea una copia de «{$record->nombre}» con todas sus líneas, rendimientos y desperdicios. Elegí la zona destino: los items toman el precio de ESA zona; los que no existan se crean con precio 0 para que los completes.")
                    ->modalSubmitActionLabel('Duplicar')
                    ->schema([
                        Select::make('zona_destino_id')
                            ->label('Zona destino')
                            ->options(fn (): array => Zona::activas()->orderBy('nombre')->pluck('nombre', 'id')->all())
                            ->default(fn (Ficha $record): int => $record->zona_id)
                            ->required()
                            ->native(false)
                            ->helperText('Misma zona = copia para ajustar. Otra zona = la ficha con los precios de esa zona.'),
                    ])
                    ->action(function (Ficha $record, array $data): void {
                        $zonaDestino = Zona::findOrFail((int) $data['zona_destino_id']);

                        $copia = app(DuplicarFichaAOtraZona::class)
                            ->ejecutar($record, $zonaDestino)['ficha_destino'];

                        // Misma zona → distingue el nombre para no confundir.
                        if ($zonaDestino->id === $record->zona_id) {
                            $copia->update(['nombre' => $record->nombre.' (COPIA)']);
                        }

                        Notification::make()
                            ->success()
                            ->title('Ficha duplicada')
                            ->body("Se creó {$copia->codigo} en zona {$zonaDestino->codigo}. Editala para ajustar.")
                            ->actions([
                                Action::make('editar')
                                    ->label('Editar copia')
                                    ->url(FichaResource::getUrl('edit', ['record' => $copia]))
                                    ->button(),
                            ])
                            ->send();
                    }),
                Action::make('recalcular')
                    ->label('Recalcular')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Ficha $record): bool => $record->lineas()->exists())
                    ->action(function (Ficha $record): void {
                        $resultado = app(CalcularPrecioFichaService::class)
                            ->recalcularYPersistir($record);

                        Notification::make()
                            ->success()
                            ->title("Ficha {$record->codigo} recalculada")
                            ->body("Precio venta: L {$resultado->precioVenta}")
                            ->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('recalcular_seleccionadas')
                        ->label('Recalcular seleccionadas')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Esto recalculará el cache de precio de todas las fichas seleccionadas con los precios actuales de los items. Puede tomar varios segundos para >50 fichas.')
                        ->action(function (Collection $records): void {
                            $service = app(CalcularPrecioFichaService::class);
                            $count = 0;

                            foreach ($records as $ficha) {
                                /** @var Ficha $ficha */
                                $service->recalcularYPersistir($ficha);
                                $count++;
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} fichas recalculadas")
                                ->body('Los caches de precio están al día con los precios actuales.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aún no hay fichas APU')
            ->emptyStateDescription('Crea la primera ficha de análisis de precio unitario. Necesitas al menos una zona y items en su base de precios.')
            ->emptyStateIcon('heroicon-o-document-chart-bar');
    }

    /**
     * Indica si el cache de la ficha está stale: hay algún item
     * referenciado con `precio_actualizado_at` posterior a
     * `precio_calculado_at` de la ficha.
     *
     * Se computa por fila en el listado. Para tablas con cientos de
     * fichas esto puede dispararse N+1 si no está cargado lineas.item;
     * el resource ya hace `with()` global pero NO de las líneas (porque
     * el listado generalmente no las muestra). Para Sesión 2 aceptamos
     * la query extra por fila — en Sprint 3 podemos optimizar con
     * subquery en getEloquentQuery si el volumen lo justifica.
     */
    private static function tieneCacheDesactualizado(Ficha $ficha): bool
    {
        if ($ficha->precio_calculado_at === null) {
            return true;
        }

        return $ficha->lineas()
            ->where('tipo', 'item')
            ->whereHas('item', function (Builder $query) use ($ficha): void {
                $query->where('precio_actualizado_at', '>', $ficha->precio_calculado_at);
            })
            ->exists();
    }
}
