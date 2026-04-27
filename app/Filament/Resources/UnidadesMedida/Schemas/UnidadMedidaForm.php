<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnidadesMedida\Schemas;

use App\Models\UnidadMedida;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class UnidadMedidaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('unidad_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Datos de la unidad')
                        ->icon('heroicon-o-scale')
                        ->schema([
                            TextInput::make('codigo')
                                ->label('Código')
                                ->required()
                                ->maxLength(20)
                                ->unique(ignoreRecord: true)
                                ->mayusculas()
                                ->prefixIcon('heroicon-o-hashtag')
                                ->placeholder('M2, BOLSA, JDR')
                                ->helperText('Único en todo el sistema. Se guarda siempre en mayúsculas.'),
                            TextInput::make('nombre')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(80)
                                ->mayusculas()
                                ->prefixIcon('heroicon-o-tag')
                                ->placeholder('METRO CUADRADO'),
                            TextInput::make('simbolo')
                                ->label('Símbolo')
                                ->maxLength(10)
                                ->prefixIcon('heroicon-o-variable')
                                ->placeholder('m², kg, ml')
                                ->helperText('Opcional. NO se uppercase: m² ≠ M² visualmente.'),
                        ])
                        ->columns(2),

                    Tab::make('Estado')
                        ->icon('heroicon-o-power')
                        ->schema([
                            Toggle::make('activo')
                                ->label('Unidad activa')
                                ->default(true)
                                ->onColor('success')
                                ->offColor('danger')
                                ->helperText('Si se desactiva, no aparece al crear nuevos items pero los existentes siguen funcionando.')
                                ->columnSpanFull(),

                            Section::make('Información del registro')
                                ->icon('heroicon-o-information-circle')
                                ->visible(fn (string $operation): bool => $operation === 'edit')
                                ->schema([
                                    Placeholder::make('items_count')
                                        ->label('Items que la usan')
                                        ->content(fn (?UnidadMedida $record): string => $record !== null
                                            ? (string) $record->items()->count()
                                            : '—'),
                                    Placeholder::make('creada_at')
                                        ->label('Creada')
                                        ->content(fn (?UnidadMedida $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
                                    Placeholder::make('cambios_registrados')
                                        ->label('Cambios registrados')
                                        ->content(function (?UnidadMedida $record): string {
                                            if ($record === null) {
                                                return '—';
                                            }

                                            $count = $record->activities()->count();

                                            return $count === 1 ? '1 cambio' : "{$count} cambios";
                                        }),
                                ])
                                ->columns(3)
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }
}
