<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Schemas;

use App\Enums\EstadoCuentaPorCobrar;
use App\Models\CuentaPorCobrar;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CuentaPorCobrarInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cuenta por cobrar')
                ->icon('heroicon-o-document-currency-dollar')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('codigo')->label('Código')->weight('bold')->copyable(),
                        TextEntry::make('cliente.nombre')->label('Cliente'),
                        TextEntry::make('proyecto.nombre')->label('Obra')->placeholder('—'),
                        TextEntry::make('concepto')->label('Concepto')->placeholder('—'),
                        TextEntry::make('estado')
                            ->label('Estado')
                            ->badge()
                            ->color(fn (EstadoCuentaPorCobrar $state): string => $state->getColor())
                            ->icon(fn (EstadoCuentaPorCobrar $state): string => $state->getIcon())
                            ->formatStateUsing(fn (EstadoCuentaPorCobrar $state): string => $state->getLabel()),
                        TextEntry::make('monto_original')->label('Monto original')->money('HNL'),
                        TextEntry::make('saldo')
                            ->label('Saldo pendiente')
                            ->money('HNL')
                            ->weight('bold')
                            ->color(fn (CuentaPorCobrar $record): string => $record->estado->getColor()),
                        TextEntry::make('fecha_vencimiento')
                            ->label('Vence')
                            ->date('d/M/Y')
                            ->color(fn (CuentaPorCobrar $record): string => $record->estado !== EstadoCuentaPorCobrar::Pagada
                                && $record->fecha_vencimiento->isPast()
                                    ? 'danger'
                                    : 'gray'),
                        TextEntry::make('fecha_emision')->label('Emisión')->date('d/M/Y'),
                    ]),
                ]),
        ]);
    }
}
