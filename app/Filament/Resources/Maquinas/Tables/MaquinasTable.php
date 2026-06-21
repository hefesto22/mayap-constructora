<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Tables;

use App\Enums\EstadoMaquina;
use App\Enums\TipoMaquina;
use App\Filament\Resources\Maquinas\Actions\AccionEnviarAMantenimiento;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MaquinasTable
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
                    ->icon('heroicon-o-truck')
                    ->limit(35),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (TipoMaquina $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('horometro_actual')
                    ->label('Horómetro')
                    ->numeric(2)
                    ->suffix(' h')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('tarifa_hora')
                    ->label('Tarifa/h')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoMaquina $state): string => $state->getColor())
                    ->icon(fn (EstadoMaquina $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoMaquina $state): string => $state->getLabel())
                    ->sortable(),
                ToggleColumn::make('activo')
                    ->label('Activa')
                    ->onColor('success')
                    ->offColor('danger'),
            ])
            ->defaultSort('codigo', 'asc')
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(TipoMaquina::options()),
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoMaquina::options()),
                TernaryFilter::make('activo')
                    ->label('Estado de registro')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
            ])
            ->recordActions([
                EditAction::make(),
                AccionEnviarAMantenimiento::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
