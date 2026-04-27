<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Tables;

use App\Enums\CategoriaItem;
use App\Models\Zona;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabla de items — corazón visual del Sprint 1.
 *
 * Patrones clave:
 * - Filtro de zona PERSISTENTE en sesión (Filament v4: persistFiltersInSession).
 *   Mauricio entra una vez con zona=SRC, sale, vuelve mañana, sigue en SRC.
 * - Filtro de categoría con badge de color (mismo enum tipado).
 * - Money column en HNL con formato local.
 * - Indicador "precio viejo" (>90 días) como columna que se puede activar.
 * - Búsqueda simultánea en código + nombre.
 */
class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Código copiado'),
                TextColumn::make('zona.codigo')
                    ->label('Zona')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('categoria')
                    ->label('Categoría')
                    ->badge()
                    ->sortable(),
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->tooltip(fn ($record): ?string => strlen((string) $record->nombre) > 60 ? $record->nombre : null)
                    ->wrap(),
                TextColumn::make('unidadMedida.codigo')
                    ->label('Unidad')
                    ->sortable()
                    ->toggleable(),
                // Precio editable INLINE — el usuario edita directamente en el listado
                // sin abrir la página completa de edición. Al hacer blur o presionar
                // Enter se persiste vía AJAX. Validación numeric/min:0 a nivel input.
                // Al guardar dispara el ItemObserver que actualiza precio_actualizado_at.
                TextInputColumn::make('precio_unitario')
                    ->label('Precio (L.)')
                    ->type('number')
                    ->rules(['numeric', 'min:0'])
                    ->sortable(),
                TextColumn::make('precio_actualizado_at')
                    ->label('Precio actualizado')
                    ->since()
                    ->sortable()
                    ->placeholder('Nunca')
                    ->tooltip(fn ($record): ?string => $record->precio_actualizado_at?->format('d/m/Y H:i'))
                    ->color(fn ($record): string => $record->precio_actualizado_at === null
                        || $record->precio_actualizado_at->lt(now()->subDays(90))
                            ? 'danger'
                            : 'success'),
                TextColumn::make('observaciones_precio')
                    ->label('Observaciones')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('activo')
                    ->label('Activo')
                    ->onColor('success')
                    ->offColor('danger'),
                TextColumn::make('updated_at')
                    ->label('Modificado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre', 'asc')
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
                SelectFilter::make('categoria')
                    ->label('Categoría')
                    ->options(CategoriaItem::options())
                    ->multiple(),
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
                Filter::make('precios_desactualizados')
                    ->label('Precios viejos (>90 días)')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->preciosDesactualizados(90)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aún no hay items en la base de precios')
            ->emptyStateDescription('Comienza agregando el primer item. Asegúrate de tener al menos una zona y unidades de medida creadas.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }
}
