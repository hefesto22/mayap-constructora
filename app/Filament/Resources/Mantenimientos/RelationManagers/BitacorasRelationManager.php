<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos\RelationManagers;

use App\Enums\FaseMantenimiento;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Historial de diagnósticos y avances del mantenimiento — SOLO LECTURA.
 * Cada entrada nace de "Registrar avance" (o del cierre al finalizar) y
 * lleva fecha y hora automáticas, la fase, el detalle y quién lo hizo.
 * Nunca se edita ni se borra: es la bitácora.
 */
class BitacorasRelationManager extends RelationManager
{
    protected static string $relationship = 'bitacoras';

    protected static ?string $title = 'Historial de diagnósticos y avances';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clipboard-document-list';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha y hora')
                    ->dateTime('d/M/Y H:i')
                    ->sortable(),
                TextColumn::make('fase')
                    ->label('Fase')
                    ->badge()
                    ->color(fn (FaseMantenimiento $state): string => $state->getColor())
                    ->icon(fn (FaseMantenimiento $state): string => $state->getIcon())
                    ->formatStateUsing(fn (FaseMantenimiento $state): string => $state->getLabel()),
                TextColumn::make('detalle')
                    ->label('Diagnóstico / avance')
                    ->wrap(),
                TextColumn::make('user.name')
                    ->label('Registró')
                    ->placeholder('Sistema'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
