<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Bitácora de cobros de una cuenta por cobrar — solo lectura. Los cobros se
 * crean exclusivamente por la acción Cobrar (CobrarService), nunca a mano.
 */
class CobrosRelationManager extends RelationManager
{
    protected static string $relationship = 'cobros';

    protected static ?string $title = 'Cobros';

    protected static string|BackedEnum|null $icon = 'heroicon-o-banknotes';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable(),
                TextColumn::make('monto')
                    ->label('Monto')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('HNL')->label('Total cobrado')),
                TextColumn::make('metodo')
                    ->label('Método')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('referencia')
                    ->label('Referencia')
                    ->placeholder('—'),
                TextColumn::make('user.name')
                    ->label('Registró')
                    ->placeholder('Sistema'),
                TextColumn::make('notas')
                    ->label('Notas')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->defaultSort('fecha', 'asc')
            ->paginated(false);
    }
}
