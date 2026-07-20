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
                    ->default(Periodicidad::Quincenal->value)
                    ->required()
                    ->live()
                    ->native(false)
                    // Decisión Mauricio 2026-07-20: cada empleado tiene SU
                    // frecuencia — la planilla solo ofrece a los suyos.
                    ->helperText('Al agregar personal, solo se ofrecen los empleados de esta frecuencia de pago.'),

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
                    ->description('Un renglón por empleado. Monto, retención y neto se calculan al guardar según el tipo de pago.')
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
                                    // Solo empleados de la frecuencia de ESTA
                                    // planilla (quincenal → quincenales...).
                                    ->options(fn (Get $get): array => Empleado::query()
                                        ->activos()
                                        ->dePeriodicidad((string) $get('../../periodicidad'))
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
                                            $set('retencion_porcentaje', $empleado->tipo_pago->retencionSugerida());
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
                                    ->label(fn (Get $get): string => match ($get('tipo_pago')) {
                                        TipoPago::Salario->value    => 'Salario',
                                        TipoPago::Honorarios->value => 'Honorarios del período',
                                        default                     => 'Tarifa/día',
                                    })
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

                                // Retención (12.5% sugerido en honorarios,
                                // editable — "una cosa así del 12.5%").
                                TextInput::make('retencion_porcentaje')
                                    ->label('Retención %')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step('any')
                                    ->suffix('%')
                                    ->visible(fn (Get $get): bool => $get('tipo_pago') === TipoPago::Honorarios->value)
                                    ->helperText('ISR sobre honorarios. Se resta del neto del recibo.'),

                                TextInput::make('deducciones')
                                    ->label('Deducciones')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('any')
                                    ->prefix('L.')
                                    ->default(0)
                                    ->helperText('Adelantos u otros descuentos; se restan del neto.'),

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
