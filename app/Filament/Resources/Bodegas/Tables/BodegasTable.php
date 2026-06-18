<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bodegas\Tables;

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

class BodegasTable
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
                    ->copyable(),
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-building-storefront'),
                TextColumn::make('responsable')
                    ->label('Responsable')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('existencias_count')
                    ->label('Items en stock')
                    ->counts('existencias')
                    ->badge()
                    ->color('info')
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
            ->defaultSort('nombre', 'asc')
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
                        if ($record->existencias()->exists()) {
                            throw new Exception(
                                'No se puede eliminar esta bodega: tiene existencias registradas. Márcala como inactiva en su lugar.'
                            );
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function ($records): void {
                            $records->each(function (Model $record): void {
                                if ($record->existencias()->exists()) {
                                    throw new Exception(
                                        "No se puede eliminar la bodega {$record->nombre}: tiene existencias registradas."
                                    );
                                }
                            });
                        }),
                ]),
            ]);
    }
}
