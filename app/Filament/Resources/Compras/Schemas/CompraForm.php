<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Schemas;

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Models\Compra;
use App\Models\Material;
use App\Models\Proveedor;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CompraForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('compra_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabDatos(),
                    self::tabLineas(),
                    self::tabEstado(),
                ]),
        ]);
    }

    private static function tabDatos(): Tab
    {
        return Tab::make('Datos de la compra')
            ->icon('heroicon-o-shopping-cart')
            ->schema([
                TextInput::make('codigo')
                    ->label('Código')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->prefixIcon('heroicon-o-hashtag')
                    ->helperText('Se genera automáticamente: COM-2026-00001, ...'),

                Select::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre', fn ($query) => $query->where('activo', true)->orderBy('nombre'))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    // Hereda la condición de pago habitual del proveedor para
                    // ahorrar clics. El usuario puede cambiarla en esta compra.
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        if ($state === null) {
                            return;
                        }

                        $proveedor = Proveedor::query()->find($state);

                        if ($proveedor instanceof Proveedor) {
                            $set('condicion_pago', $proveedor->condicion_pago->value);
                        }
                    })
                    ->disabledOn('edit'),

                Select::make('bodega_id')
                    ->label('Bodega destino')
                    ->relationship('bodega', 'nombre', function ($query) {
                        $query->where('activo', true)->orderBy('nombre');

                        // El usuario solo compra hacia SUS bodegas (Fase 2).
                        $user = auth()->user();

                        if ($user instanceof User) {
                            $query->visibleParaUsuario($user);
                        }

                        return $query;
                    })
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabledOn('edit')
                    ->helperText('Bodega donde entra el stock al confirmar.'),

                DatePicker::make('fecha')
                    ->label('Fecha de compra')
                    ->default(now())
                    ->required()
                    ->native(false),

                Select::make('condicion_pago')
                    ->label('Condición de pago')
                    ->options(CondicionPago::options())
                    ->default(CondicionPago::Contado->value)
                    ->required()
                    ->native(false)
                    ->helperText('Se hereda del proveedor al elegirlo. Crédito genera una cuenta por pagar con su plazo; contado no.'),

                TextInput::make('numero_factura')
                    ->label('N.º de factura')
                    ->maxLength(50)
                    ->placeholder('Factura del proveedor'),

                Toggle::make('aplica_isv')
                    ->label('Aplica ISV (15%)')
                    ->default(true)
                    ->live()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function tabLineas(): Tab
    {
        return Tab::make('Materiales comprados')
            ->icon('heroicon-o-cube')
            ->schema([
                Repeater::make('lineas')
                    ->relationship()
                    ->label('Líneas')
                    ->addActionLabel('+ Agregar material')
                    ->reorderable(false)
                    ->defaultItems(1)
                    ->minItems(1)
                    ->columnSpanFull()
                    ->disabled(function (?Compra $record): bool {
                        $estado = $record?->getAttribute('estado');

                        return $estado instanceof EstadoCompra && ! $estado->permiteEditar();
                    })
                    ->schema([
                        Select::make('material_id')
                            ->label('Material')
                            ->relationship('material', 'nombre', fn ($query) => $query->where('activo', true)->orderBy('nombre'))
                            ->getOptionLabelFromRecordUsing(fn (Material $record): string => "{$record->codigo} — {$record->nombre}")
                            ->searchable(['codigo', 'nombre'])
                            ->preload()
                            ->required()
                            ->columnSpan(2),

                        TextInput::make('cantidad')
                            ->label('Cantidad')
                            ->numeric()
                            ->required()
                            ->minValue(0.0001)
                            ->step('any'),

                        TextInput::make('costo_unitario')
                            ->label('Costo unit. (neto)')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->prefix('L.')
                            ->step('any'),
                    ])
                    ->columns(4),
            ]);
    }

    private static function tabEstado(): Tab
    {
        return Tab::make('Estado y totales')
            ->icon('heroicon-o-flag')
            ->visible(fn (string $operation): bool => $operation === 'edit')
            ->schema([
                Section::make('Resumen')
                    ->icon('heroicon-o-information-circle')
                    ->schema([
                        Placeholder::make('estado_actual')
                            ->label('Estado')
                            ->content(fn (?Compra $record): string => $record?->estado->getLabel() ?? '—'),
                        Placeholder::make('subtotal_label')
                            ->label('Subtotal')
                            ->content(fn (?Compra $record): string => $record !== null
                                ? 'L. '.number_format((float) $record->subtotal_cache, 2)
                                : 'L. 0.00'),
                        Placeholder::make('isv_label')
                            ->label('ISV')
                            ->content(fn (?Compra $record): string => $record !== null
                                ? 'L. '.number_format((float) $record->isv_cache, 2)
                                : 'L. 0.00'),
                        Placeholder::make('total_label')
                            ->label('Total')
                            ->content(fn (?Compra $record): string => $record !== null
                                ? 'L. '.number_format((float) $record->total_cache, 2)
                                : 'L. 0.00'),
                    ])
                    ->columns(2),
            ]);
    }
}
