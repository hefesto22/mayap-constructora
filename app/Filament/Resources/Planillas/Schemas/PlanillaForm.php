<?php

declare(strict_types=1);

namespace App\Filament\Resources\Planillas\Schemas;

use App\Enums\EstadoPlanilla;
use App\Enums\Periodicidad;
use App\Enums\TipoPago;
use App\Models\Empleado;
use App\Models\Planilla;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PlanillaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('planilla_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabDatos(),
                    self::tabLineas(),
                ]),
        ]);
    }

    private static function tabDatos(): Tab
    {
        return Tab::make('Datos de la planilla')
            ->icon('heroicon-o-calendar')
            ->schema([
                TextInput::make('codigo')
                    ->label('Código')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->prefixIcon('heroicon-o-hashtag')
                    ->helperText('Se genera automáticamente: PLA-2026-00001, ...'),

                Select::make('periodicidad')
                    ->label('Periodicidad')
                    ->options(Periodicidad::options())
                    ->default(Periodicidad::Semanal->value)
                    ->required()
                    ->native(false),

                DatePicker::make('fecha_inicio')
                    ->label('Desde')
                    ->default(now()->startOfWeek())
                    ->required()
                    ->native(false),

                DatePicker::make('fecha_fin')
                    ->label('Hasta')
                    ->default(now()->endOfWeek())
                    ->required()
                    ->native(false)
                    ->afterOrEqual('fecha_inicio'),
            ])
            ->columns(3);
    }

    private static function tabLineas(): Tab
    {
        return Tab::make('Pagos del personal')
            ->icon('heroicon-o-users')
            ->schema([
                Section::make('Líneas de pago')
                    ->description('Un renglón por empleado. El monto se calcula al guardar según el tipo de pago.')
                    ->schema([
                        Repeater::make('lineas')
                            ->relationship()
                            ->label('Pagos')
                            ->addActionLabel('+ Agregar empleado')
                            ->reorderable(false)
                            ->defaultItems(1)
                            ->columnSpanFull()
                            ->disabled(function (?Planilla $record): bool {
                                $estado = $record?->getAttribute('estado');

                                return $estado instanceof EstadoPlanilla && ! $estado->permiteEditar();
                            })
                            ->schema([
                                Hidden::make('tipo_pago')->default(TipoPago::Jornal->value),

                                Select::make('empleado_id')
                                    ->label('Empleado')
                                    ->options(fn (): array => Empleado::query()
                                        ->activos()
                                        ->orderBy('nombre')
                                        ->get()
                                        ->mapWithKeys(fn (Empleado $e): array => [
                                            $e->id => "{$e->codigo} — {$e->nombre}",
                                        ])
                                        ->all())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                                        if ($state === null) {
                                            return;
                                        }

                                        $empleado = Empleado::query()->find($state);

                                        if ($empleado instanceof Empleado) {
                                            $set('tipo_pago', $empleado->tipo_pago->value);
                                            $set('tarifa_aplicada', (string) $empleado->tarifa_base);
                                        }
                                    })
                                    ->columnSpan(2),

                                Select::make('proyecto_id')
                                    ->label('Obra (opcional)')
                                    ->relationship('proyecto', 'nombre')
                                    ->searchable()
                                    ->preload()
                                    ->placeholder('Sin obra (overhead)')
                                    ->columnSpan(2),

                                TextInput::make('dias_trabajados')
                                    ->label('Días')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('any')
                                    ->visible(fn (Get $get): bool => $get('tipo_pago') === TipoPago::Jornal->value),

                                TextInput::make('tarifa_aplicada')
                                    ->label(fn (Get $get): string => $get('tipo_pago') === TipoPago::Salario->value ? 'Salario' : 'Tarifa/día')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('any')
                                    ->prefix('L.')
                                    ->visible(fn (Get $get): bool => $get('tipo_pago') !== TipoPago::Destajo->value),

                                TextInput::make('monto_bruto')
                                    ->label('Monto (tarea)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('any')
                                    ->prefix('L.')
                                    ->default(0)
                                    ->visible(fn (Get $get): bool => $get('tipo_pago') === TipoPago::Destajo->value),

                                TextInput::make('descripcion')
                                    ->label('Descripción de la tarea')
                                    ->visible(fn (Get $get): bool => $get('tipo_pago') === TipoPago::Destajo->value)
                                    ->columnSpan(2),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
