<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CuentaPorCobrarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cuenta por cobrar')
                ->icon('heroicon-o-document-currency-dollar')
                ->description('Registra lo que un cliente le debe a la empresa. El saldo inicia igual al monto.')
                ->schema([
                    TextInput::make('codigo')
                        ->label('Código')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (string $operation): bool => $operation === 'edit')
                        ->prefixIcon('heroicon-o-hashtag')
                        ->helperText('Se genera automáticamente: CXC-2026-00001, ...'),

                    Select::make('cliente_id')
                        ->label('Cliente')
                        ->relationship('cliente', 'nombre', fn ($query) => $query->orderBy('nombre'))
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make('proyecto_id')
                        ->label('Obra (opcional)')
                        ->relationship('proyecto', 'nombre')
                        ->searchable()
                        ->preload()
                        ->placeholder('Sin obra específica'),

                    TextInput::make('concepto')
                        ->label('Concepto')
                        ->maxLength(255)
                        ->placeholder('Anticipo de obra, estimación #2, etc.')
                        ->columnSpanFull(),

                    TextInput::make('monto_original')
                        ->label('Monto')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->step('any')
                        ->prefix('L.'),

                    DatePicker::make('fecha_emision')
                        ->label('Fecha de emisión')
                        ->default(now())
                        ->required()
                        ->native(false),

                    DatePicker::make('fecha_vencimiento')
                        ->label('Vence')
                        ->default(now()->addDays(30))
                        ->required()
                        ->native(false)
                        ->afterOrEqual('fecha_emision'),

                    Textarea::make('notas')
                        ->label('Notas')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
