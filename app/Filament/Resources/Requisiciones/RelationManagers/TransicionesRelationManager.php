<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\RelationManagers;

use App\Enums\EstadoRequisicion;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Bitácora de transiciones de una requisición — solo lectura. Responde
 * "quién pasó la requisición de qué estado a cuál, cuándo y con qué nota".
 * Es el registro de auditoría del flujo (la trazabilidad que el dueño pide).
 */
class TransicionesRelationManager extends RelationManager
{
    protected static string $relationship = 'transiciones';

    protected static ?string $title = 'Bitácora';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->dateTime('d/M/Y H:i')
                    ->sortable(),
                TextColumn::make('estado_origen')
                    ->label('De')
                    ->badge()
                    ->formatStateUsing(fn (?EstadoRequisicion $state): string => $state?->getLabel() ?? 'Creación')
                    ->color(fn (?EstadoRequisicion $state): string => $state?->getColor() ?? 'gray'),
                TextColumn::make('estado_destino')
                    ->label('A')
                    ->badge()
                    ->formatStateUsing(fn (EstadoRequisicion $state): string => $state->getLabel())
                    ->color(fn (EstadoRequisicion $state): string => $state->getColor()),
                TextColumn::make('user.name')
                    ->label('Responsable')
                    ->placeholder('Sistema'),
                TextColumn::make('nota')
                    ->label('Nota')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated(false);
    }
}
