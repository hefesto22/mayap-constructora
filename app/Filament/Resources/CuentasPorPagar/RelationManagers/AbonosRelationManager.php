<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar\RelationManagers;

use App\Models\Abono;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Bitácora de abonos de una cuenta por pagar — solo lectura. Responde
 * "cuánto se abonó, cuándo, con qué método, con qué comprobante y quién
 * lo registró". Los abonos se crean exclusivamente por la acción Abonar
 * (AbonarService), nunca a mano.
 */
class AbonosRelationManager extends RelationManager
{
    protected static string $relationship = 'abonos';

    protected static ?string $title = 'Abonos';

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
                    ->summarize(Sum::make()->money('HNL')->label('Total abonado')),
                TextColumn::make('metodo')
                    ->label('Método')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('referencia')
                    ->label('Referencia')
                    ->placeholder('—'),
                // Miniatura del comprobante de transferencia — clic para
                // verlo completo. Se purga tras archivarse en el reporte
                // mensual de pagos (queda el PDF de respaldo).
                ImageColumn::make('foto_comprobante')
                    ->label('Comprobante')
                    ->disk('public')
                    ->imageHeight(40)
                    ->url(
                        fn (Abono $record): ?string => $record->foto_comprobante !== null
                            ? Storage::disk('public')->url($record->foto_comprobante)
                            : null,
                        shouldOpenInNewTab: true,
                    )
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
