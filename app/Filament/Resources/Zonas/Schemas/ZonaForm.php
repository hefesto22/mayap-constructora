<?php

declare(strict_types=1);

namespace App\Filament\Resources\Zonas\Schemas;

use App\Models\Zona;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ZonaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('zona_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Datos de la zona')
                        ->icon('heroicon-o-map-pin')
                        ->schema([
                            TextInput::make('codigo')
                                ->label('Código')
                                ->required()
                                ->maxLength(10)
                                ->unique(ignoreRecord: true)
                                ->mayusculas()
                                ->prefixIcon('heroicon-o-hashtag')
                                ->placeholder('SRC, TGU, SPS')
                                ->helperText('Único en todo el sistema. Se guarda siempre en mayúsculas.'),
                            TextInput::make('nombre')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(120)
                                ->mayusculas()
                                ->prefixIcon('heroicon-o-tag')
                                ->placeholder('SANTA ROSA DE COPÁN'),
                            Textarea::make('descripcion')
                                ->label('Descripción')
                                ->rows(3)
                                ->maxLength(255)
                                ->mayusculas()
                                ->placeholder('NOTAS OPERATIVAS OPCIONALES')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Tab::make('Inicialización')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->visible(fn (string $operation): bool => $operation === 'create')
                        ->schema([
                            Select::make('zona_origen_id')
                                ->label('Heredar base de precios desde')
                                ->options(fn (): array => Zona::activas()
                                    ->withCount(['items' => fn ($q) => $q->where('activo', true)])
                                    ->orderBy('nombre')
                                    ->get()
                                    ->mapWithKeys(fn (Zona $z): array => [
                                        $z->id => "{$z->nombre} ({$z->items_count} items)",
                                    ])
                                    ->all())
                                ->placeholder('Empezar zona vacía (sin items)')
                                ->searchable()
                                ->preload()
                                ->prefixIcon('heroicon-o-document-duplicate')
                                ->helperText(
                                    'Opcional. Si seleccionas una zona, copiaremos todos sus items '
                                    .'activos (precios, nombres, categorías, unidades) como punto de '
                                    .'partida. Después puedes ajustar precios sin afectar la zona origen — '
                                    .'son items independientes.'
                                ),
                        ]),

                    Tab::make('Estado')
                        ->icon('heroicon-o-power')
                        ->schema([
                            Toggle::make('activa')
                                ->label('Zona activa')
                                ->default(true)
                                ->onColor('success')
                                ->offColor('danger')
                                ->helperText('Una zona inactiva no aparece al crear nuevos items o presupuestos.')
                                ->columnSpanFull(),

                            Section::make('Información del registro')
                                ->icon('heroicon-o-information-circle')
                                ->visible(fn (string $operation): bool => $operation === 'edit')
                                ->schema([
                                    Placeholder::make('items_count')
                                        ->label('Items en la base de precios')
                                        ->content(fn (?Zona $record): string => $record !== null
                                            ? (string) $record->items()->count()
                                            : '—'),
                                    Placeholder::make('creada_at')
                                        ->label('Creada')
                                        ->content(fn (?Zona $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
                                    Placeholder::make('cambios_registrados')
                                        ->label('Cambios registrados')
                                        ->content(function (?Zona $record): string {
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
