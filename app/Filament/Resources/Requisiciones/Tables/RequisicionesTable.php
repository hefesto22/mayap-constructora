<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Tables;

use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Requisiciones\Actions\AccionesTransicion;
use App\Models\Requisicion;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RequisicionesTable
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
                TextColumn::make('proyecto.nombre')
                    ->label('Obra')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoRequisicion $state): string => $state->getColor())
                    ->icon(fn (EstadoRequisicion $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoRequisicion $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('lineas_count')
                    ->label('Items')
                    ->counts('lineas')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('fecha_necesaria')
                    ->label('Necesaria')
                    ->date('d/M/Y')
                    ->sortable()
                    ->color(fn (Requisicion $record): string => $record->fecha_necesaria->isPast()
                        && ! $record->estado->esTerminal() ? 'danger' : 'gray'),
                TextColumn::make('solicitante.name')
                    ->label('Solicitante')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/M/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('codigo', 'desc')
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoRequisicion::options()),
                SelectFilter::make('proyecto_id')
                    ->label('Obra')
                    ->relationship('proyecto', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Requisicion $record): bool => $record->estado->permiteEditarLineas()),
                AccionesTransicion::autorizar(),
                AccionesTransicion::registrarEntrada(),
                AccionesTransicion::despachar(),
                AccionesTransicion::marcarEnTransito(),
                AccionesTransicion::recibir(),
                AccionesTransicion::conciliar(),
                AccionesTransicion::rechazar(),
            ])
            ->paginated([25, 50, 100])
            ->poll('60s');
    }
}
