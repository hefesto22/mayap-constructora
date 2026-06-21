<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar\Schemas;

use App\Enums\EstadoCuentaPorPagar;
use App\Models\CuentaPorPagar;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CuentaPorPagarInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cuenta por pagar')
                ->icon('heroicon-o-document-currency-dollar')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('compra.codigo')
                            ->label('Compra')
                            ->weight('bold')
                            ->copyable(),
                        TextEntry::make('proveedor.nombre')
                            ->label('Proveedor'),
                        TextEntry::make('estado')
                            ->label('Estado')
                            ->badge()
                            ->color(fn (EstadoCuentaPorPagar $state): string => $state->getColor())
                            ->icon(fn (EstadoCuentaPorPagar $state): string => $state->getIcon())
                            ->formatStateUsing(fn (EstadoCuentaPorPagar $state): string => $state->getLabel()),
                        TextEntry::make('monto_original')
                            ->label('Monto original')
                            ->money('HNL'),
                        TextEntry::make('saldo')
                            ->label('Saldo pendiente')
                            ->money('HNL')
                            ->weight('bold')
                            ->color(fn (CuentaPorPagar $record): string => $record->estado->getColor()),
                        TextEntry::make('fecha_vencimiento')
                            ->label('Vence')
                            ->date('d/M/Y')
                            ->color(fn (CuentaPorPagar $record): string => $record->estado !== EstadoCuentaPorPagar::Pagada
                                && $record->fecha_vencimiento->isPast()
                                    ? 'danger'
                                    : 'gray'),
                        TextEntry::make('fecha_emision')
                            ->label('Emisión')
                            ->date('d/M/Y'),
                    ]),
                ]),
        ]);
    }
}
