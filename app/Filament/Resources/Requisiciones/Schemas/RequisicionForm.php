<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Schemas;

use App\Enums\EstadoRequisicion;
use App\Models\Requisicion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class RequisicionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('requisicion_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Datos de la requisición')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->schema([
                            TextInput::make('codigo')
                                ->label('Código')
                                ->disabled()
                                ->dehydrated(false)
                                ->visible(fn (string $operation): bool => $operation === 'edit')
                                ->prefixIcon('heroicon-o-hashtag')
                                ->helperText('Se genera automáticamente: REQ-2026-00001, ...'),

                            Select::make('proyecto_id')
                                ->label('Obra (proyecto)')
                                ->relationship('proyecto', 'nombre', fn ($query) => $query->orderBy('nombre'))
                                ->searchable()
                                ->preload()
                                ->required()
                                ->disabledOn('edit')
                                ->helperText('La obra que solicita el material. No se cambia después de crear.'),

                            DatePicker::make('fecha_solicitud')
                                ->label('Fecha de solicitud')
                                ->default(now())
                                ->required()
                                ->native(false),

                            DatePicker::make('fecha_necesaria')
                                ->label('Fecha necesaria en obra')
                                ->required()
                                ->native(false)
                                ->minDate(fn (callable $get) => $get('fecha_solicitud'))
                                ->helperText('Cuándo debe estar el material en obra sí o sí.'),

                            Textarea::make('notas')
                                ->label('Notas')
                                ->rows(2)
                                ->maxLength(500)
                                ->mayusculas()
                                ->placeholder('INSTRUCCIONES O CONTEXTO OPCIONAL')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Tab::make('Materiales solicitados')
                        ->icon('heroicon-o-cube')
                        ->schema([
                            Repeater::make('lineas')
                                ->relationship()
                                ->label('Items')
                                ->addActionLabel('+ Agregar item')
                                ->reorderable(false)
                                ->defaultItems(1)
                                ->minItems(1)
                                ->columnSpanFull()
                                ->disabled(function (?Requisicion $record): bool {
                                    $estado = $record?->getAttribute('estado');

                                    return $estado instanceof EstadoRequisicion
                                        && ! $estado->permiteEditarLineas();
                                })
                                ->schema([
                                    Select::make('item_id')
                                        ->label('Item')
                                        ->relationship('item', 'nombre', fn ($query) => $query->where('activo', true)->orderBy('nombre'))
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->columnSpan(2),

                                    TextInput::make('cantidad_solicitada')
                                        ->label('Cantidad')
                                        ->numeric()
                                        ->required()
                                        ->minValue(0.0001)
                                        ->step('any'),
                                ])
                                ->columns(3),
                        ]),

                    Tab::make('Estado')
                        ->icon('heroicon-o-flag')
                        ->visible(fn (string $operation): bool => $operation === 'edit')
                        ->schema([
                            Section::make('Seguimiento')
                                ->icon('heroicon-o-information-circle')
                                ->schema([
                                    Placeholder::make('estado_actual')
                                        ->label('Estado actual')
                                        ->content(fn (?Requisicion $record): string => $record !== null
                                            ? $record->estado->getLabel()
                                            : '—'),
                                    Placeholder::make('transiciones_count')
                                        ->label('Transiciones registradas')
                                        ->content(fn (?Requisicion $record): string => $record !== null
                                            ? (string) $record->transiciones()->count()
                                            : '—'),
                                ])
                                ->columns(2),
                        ]),
                ]),
        ]);
    }
}
