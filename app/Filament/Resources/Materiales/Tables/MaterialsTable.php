<?php

declare(strict_types=1);

namespace App\Filament\Resources\Materiales\Tables;

use App\Enums\CategoriaItem;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Tabla de materiales — catálogo del recurso físico de inventario.
 *
 * La columna "Precios" muestra en cuántas zonas tiene precio de venta
 * vinculado (items.material_id), señal de qué tan integrado está el
 * material con la base de precios.
 */
class MaterialsTable
{
    /**
     * @param bool $conCategoria false cuando el Resource ya esta acotado a
     *                           UNA categoria (Materiales o Herramienta y equipo separados): la
     *                           columna y el filtro de categoria sobran.
     */
    public static function configure(Table $table, bool $conCategoria = true): Table
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
                TextColumn::make('categoria')
                    ->label('Categoría')
                    ->badge()
                    ->sortable()
                    ->visible($conCategoria),
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
                TextColumn::make('items_count')
                    ->label('Precios')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->tooltip('Zonas con precio de venta vinculado')
                    ->alignCenter(),
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
                SelectFilter::make('categoria')
                    ->label('Categoría')
                    ->options([
                        CategoriaItem::Materiales->value        => CategoriaItem::Materiales->getLabel(),
                        CategoriaItem::HerramientaEquipo->value => CategoriaItem::HerramientaEquipo->getLabel(),
                    ])
                    ->visible($conCategoria),
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
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
            ->emptyStateHeading('Aún no hay materiales registrados')
            ->emptyStateDescription('Agregá el primer material físico. Es lo que se compra, almacena y mueve en el inventario.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}
