<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnidadesMedida\Tables;

use Exception;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UnidadesMedidaTable
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
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('simbolo')
                    ->label('Símbolo')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('items_count')
                    ->label('Items en uso')
                    ->counts('items')
                    ->badge()
                    ->color('gray')
                    ->placeholder('0'),
                ToggleColumn::make('activo')
                    ->label('Activa')
                    ->onColor('success')
                    ->offColor('danger'),
                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('codigo', 'asc')
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->before(function (Model $record): void {
                        if ($record->items()->exists()) {
                            throw new Exception(
                                'No se puede eliminar esta unidad: tiene items asociados. Marca como inactiva en su lugar.'
                            );
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function ($records): void {
                            $records->each(function (Model $record): void {
                                if ($record->items()->exists()) {
                                    throw new Exception(
                                        "No se puede eliminar la unidad {$record->codigo}: tiene items asociados."
                                    );
                                }
                            });
                        }),
                ]),
            ]);
    }
}
