<?php

declare(strict_types=1);

namespace App\Filament\Resources\Empleados\Tables;

use App\Enums\TipoPago;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EmpleadosTable
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
                    ->icon('heroicon-o-user')
                    ->limit(40),
                TextColumn::make('cargo')
                    ->label('Cargo')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('tipo_pago')
                    ->label('Pago')
                    ->badge()
                    ->color(fn (TipoPago $state): string => $state->getColor())
                    ->icon(fn (TipoPago $state): string => $state->getIcon())
                    ->formatStateUsing(fn (TipoPago $state): string => $state->getLabel()),
                TextColumn::make('tarifa_base')
                    ->label('Tarifa')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable(),
                ToggleColumn::make('activo')
                    ->label('Activo')
                    ->onColor('success')
                    ->offColor('danger'),
            ])
            ->defaultSort('nombre', 'asc')
            ->filters([
                SelectFilter::make('tipo_pago')
                    ->label('Tipo de pago')
                    ->options(TipoPago::options()),
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
