<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores\Tables;

use App\Enums\CondicionPago;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProveedoresTable
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
                    ->icon('heroicon-o-building-office')
                    ->limit(40),
                TextColumn::make('rtn')
                    ->label('RTN')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('condicion_pago')
                    ->label('Pago')
                    ->badge()
                    ->color(fn (CondicionPago $state): string => $state->getColor())
                    ->icon(fn (CondicionPago $state): string => $state->getIcon())
                    ->formatStateUsing(fn (CondicionPago $state): string => $state->getLabel()),
                ToggleColumn::make('activo')
                    ->label('Activo')
                    ->onColor('success')
                    ->offColor('danger'),
            ])
            ->defaultSort('nombre', 'asc')
            ->filters([
                SelectFilter::make('condicion_pago')
                    ->label('Condición de pago')
                    ->options(CondicionPago::options()),
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
            ]);
    }
}
