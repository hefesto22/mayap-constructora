<?php

declare(strict_types=1);

namespace App\Filament\Resources\Empleados\Schemas;

use App\Enums\TipoPago;
use App\Models\Empleado;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EmpleadoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('empleado_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabIdentificacion(),
                    self::tabPago(),
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
                    ->helperText('Asignado automáticamente al crear. Patrón: EMP-{NÚMERO}.'),

                TextInput::make('nombre')
                    ->label('Nombre completo')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('JUAN PÉREZ')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-user')
                    ->columnSpanFull(),

                TextInput::make('identidad')
                    ->label('Identidad')
                    ->maxLength(20)
                    ->placeholder('0801-1990-12345')
                    ->rules(['nullable', 'regex:/^[0-9-]{8,20}$/'])
                    ->helperText('Solo números y guiones.')
                    ->prefixIcon('heroicon-o-identification'),

                TextInput::make('cargo')
                    ->label('Cargo / puesto')
                    ->maxLength(100)
                    ->placeholder('MAESTRO DE OBRA')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-briefcase'),
            ])
            ->columns(2);
    }

    private static function tabPago(): Tab
    {
        return Tab::make('Pago')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Select::make('tipo_pago')
                    ->label('Tipo de pago')
                    ->options(TipoPago::options())
                    ->default(TipoPago::Jornal->value)
                    ->required()
                    ->live()
                    ->native(false),

                TextInput::make('tarifa_base')
                    ->label(fn (Get $get): string => match ($get('tipo_pago')) {
                        TipoPago::Salario->value => 'Salario del período',
                        TipoPago::Destajo->value => 'No aplica (por tarea)',
                        default                  => 'Pago por día (jornal)',
                    })
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->default(0)
                    ->prefix('L.')
                    ->disabled(fn (Get $get): bool => $get('tipo_pago') === TipoPago::Destajo->value)
                    ->helperText(fn (Get $get): string => match ($get('tipo_pago')) {
                        TipoPago::Salario->value => 'Monto fijo que gana por período de planilla.',
                        TipoPago::Destajo->value => 'En destajo, el monto se captura por tarea en cada planilla.',
                        default                  => 'Lo que gana por cada día trabajado.',
                    }),
            ])
            ->columns(2);
    }

    private static function tabEstado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-power')
            ->schema([
                Toggle::make('activo')
                    ->label('Empleado activo')
                    ->default(true)
                    ->onColor('success')
                    ->offColor('danger')
                    ->helperText('Los empleados inactivos no aparecen al armar la planilla.')
                    ->columnSpanFull(),

                Textarea::make('notas')
                    ->label('Notas internas')
                    ->rows(3)
                    ->mayusculas()
                    ->columnSpanFull(),

                TextInput::make('creado_at')
                    ->label('Empleado creado')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->default(fn (?Empleado $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
            ]);
    }
}
