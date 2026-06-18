<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bodegas\Schemas;

use App\Models\Bodega;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class BodegaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('bodega_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Datos de la bodega')
                        ->icon('heroicon-o-building-storefront')
                        ->schema([
                            TextInput::make('codigo')
                                ->label('Código')
                                ->disabled()
                                ->dehydrated(false)
                                ->visible(fn (string $operation): bool => $operation === 'edit')
                                ->prefixIcon('heroicon-o-hashtag')
                                ->helperText('Se genera automáticamente: BOD-00001, BOD-00002, ...'),

                            TextInput::make('nombre')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(200)
                                ->mayusculas()
                                ->prefixIcon('heroicon-o-tag')
                                ->placeholder('BODEGA CENTRAL SANTA ROSA')
                                ->columnSpanFull(),

                            TextInput::make('responsable')
                                ->label('Responsable')
                                ->maxLength(150)
                                ->prefixIcon('heroicon-o-user')
                                ->placeholder('Nombre de la persona a cargo')
                                ->helperText('Persona que controla las entradas y salidas de esta bodega.'),

                            Textarea::make('direccion')
                                ->label('Dirección')
                                ->rows(2)
                                ->maxLength(500)
                                ->mayusculas()
                                ->placeholder('UBICACIÓN FÍSICA DE LA BODEGA')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Tab::make('Estado')
                        ->icon('heroicon-o-power')
                        ->schema([
                            Toggle::make('activo')
                                ->label('Bodega activa')
                                ->default(true)
                                ->onColor('success')
                                ->offColor('danger')
                                ->helperText('Una bodega inactiva no aparece al despachar ni registrar entradas.')
                                ->columnSpanFull(),

                            Section::make('Información del registro')
                                ->icon('heroicon-o-information-circle')
                                ->visible(fn (string $operation): bool => $operation === 'edit')
                                ->schema([
                                    Placeholder::make('existencias_count')
                                        ->label('Items con existencia')
                                        ->content(fn (?Bodega $record): string => $record !== null
                                            ? (string) $record->existencias()->count()
                                            : '—'),
                                    Placeholder::make('creada_at')
                                        ->label('Creada')
                                        ->content(fn (?Bodega $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
                                    Placeholder::make('cambios_registrados')
                                        ->label('Cambios registrados')
                                        ->content(function (?Bodega $record): string {
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
