<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Schemas;

use App\Enums\TipoMaquina;
use App\Models\Maquina;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class MaquinaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('maquina_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabIdentificacion(),
                    self::tabOperacion(),
                    self::tabEstado(),
                ]),
        ]);
    }

    private static function tabIdentificacion(): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-identification')
            ->schema([
                TextInput::make('codigo')
                    ->label('Código del sistema')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->prefixIcon('heroicon-o-hashtag')
                    ->helperText('Asignado automáticamente al crear. Patrón: MAQ-{NÚMERO}.'),

                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('EXCAVADORA CAT 320')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-truck')
                    ->columnSpanFull(),

                Select::make('tipo')
                    ->label('Tipo de máquina')
                    ->options(TipoMaquina::options())
                    ->default(TipoMaquina::Otro->value)
                    ->required()
                    ->native(false),

                TextInput::make('serie')
                    ->label('N.º de serie / placa')
                    ->maxLength(100)
                    ->placeholder('SN-12345')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-hashtag'),

                TextInput::make('marca')
                    ->label('Marca')
                    ->maxLength(100)
                    ->placeholder('CATERPILLAR')
                    ->mayusculas(),

                TextInput::make('modelo')
                    ->label('Modelo')
                    ->maxLength(100)
                    ->placeholder('320D')
                    ->mayusculas(),

                TextInput::make('anio')
                    ->label('Año')
                    ->numeric()
                    ->minValue(1950)
                    ->maxValue((int) date('Y') + 1)
                    ->placeholder('2020'),
            ])
            ->columns(2);
    }

    private static function tabOperacion(): Tab
    {
        return Tab::make('Operación y tarifa')
            ->icon('heroicon-o-clock')
            ->schema([
                TextInput::make('horometro_actual')
                    ->label('Horómetro actual')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->default(0)
                    ->suffix('h')
                    ->helperText('Lectura inicial del reloj de horas. Luego lo mueven los partes de trabajo.'),

                TextInput::make('kilometraje_actual')
                    ->label('Kilometraje actual')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->suffix('km')
                    ->helperText('Solo para unidades que se controlan por km (volquetas, camiones). Lectura manual: se actualiza aquí o al registrar un cambio de mantenimiento.'),

                TextInput::make('tarifa_hora')
                    ->label('Tarifa por hora (por defecto)')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->default(0)
                    ->prefix('L.')
                    ->helperText('Costo por hora que se cobra a la obra. Se puede ajustar al asignar la máquina.'),

                TextInput::make('jornada_horas')
                    ->label('Jornada estándar')
                    ->numeric()
                    ->minValue(0.5)
                    ->step('any')
                    ->default(8)
                    ->suffix('h')
                    ->required()
                    ->helperText('Horas normales por día. Por encima de esto, el parte exige motivo de horas extra.'),
            ])
            ->columns(2);
    }

    private static function tabEstado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-power')
            ->schema([
                Toggle::make('activo')
                    ->label('Máquina activa')
                    ->default(true)
                    ->onColor('success')
                    ->offColor('danger')
                    ->helperText('Las máquinas inactivas no aparecen al asignar a obras.')
                    ->columnSpanFull(),

                Textarea::make('notas')
                    ->label('Notas internas')
                    ->rows(3)
                    ->placeholder('OBSERVACIONES: ACCESORIOS, MANTENIMIENTOS PENDIENTES, OPERADOR HABITUAL')
                    ->mayusculas()
                    ->columnSpanFull(),

                TextInput::make('creado_at')
                    ->label('Máquina creada')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->default(fn (?Maquina $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
            ]);
    }
}
