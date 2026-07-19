<?php

declare(strict_types=1);

namespace App\Filament\Resources\Materiales\Schemas;

use App\Enums\CategoriaItem;
use App\Models\Material;
use App\Models\UnidadMedida;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Form de Material con Tabs (Filament v4), patrón estándar del proyecto:
 *  1. Identificación — qué es y cómo se mide.
 *  2. Estado — disponibilidad + metadata histórica.
 *
 * Solo categorías físicas (materiales, herramienta y equipo): mano de obra
 * e indirectos NO son inventariables y viven únicamente en la base de
 * precios. El código se auto-genera global (MAT-00001 / HE-00001).
 */
class MaterialForm
{
    /**
     * @param CategoriaItem|null $categoriaFija Cuando el Resource ya define
     *                                          la categoria (Materiales o Herramienta y equipo como secciones
     *                                          separadas), el select desaparece y el valor viaja fijo.
     */
    public static function configure(Schema $schema, ?CategoriaItem $categoriaFija = null): Schema
    {
        return $schema->components([
            Tabs::make('material_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabIdentificacion($categoriaFija),
                    self::tabEstado(),
                ]),
        ]);
    }

    private static function tabIdentificacion(?CategoriaItem $categoriaFija): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-identification')
            ->schema([
                $categoriaFija !== null
                    ? Hidden::make('categoria')
                        ->default($categoriaFija->value)
                        ->dehydrated()
                    : Select::make('categoria')
                        ->label('Categoría')
                        ->options([
                            CategoriaItem::Materiales->value        => CategoriaItem::Materiales->getLabel(),
                            CategoriaItem::HerramientaEquipo->value => CategoriaItem::HerramientaEquipo->getLabel(),
                        ])
                        ->default(CategoriaItem::Materiales->value)
                        ->required()
                        ->native(false)
                        ->prefixIcon('heroicon-o-squares-2x2')
                        ->disabledOn('edit')
                        ->dehydrated()
                        ->helperText('Solo recursos físicos inventariables. NO editable después de crear (el código depende de ella).'),

                TextInput::make('codigo')
                    ->label('Código del sistema')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->prefixIcon('heroicon-o-hashtag')
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->helperText('Asignado automáticamente al crear. Patrón global: {CATEGORÍA}-{NÚMERO}.'),

                Select::make('unidad_medida_id')
                    ->label('Unidad de medida')
                    ->relationship('unidadMedida', 'nombre', fn ($query) => $query->activas()->orderBy('codigo'))
                    ->getOptionLabelFromRecordUsing(fn (UnidadMedida $record): string => $record->etiqueta)
                    ->required()
                    ->searchable()
                    ->preload()
                    ->prefixIcon('heroicon-o-scale'),

                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(200)
                    ->prefixIcon('heroicon-o-tag')
                    ->placeholder('CEMENTO GRIS 42.5KG')
                    ->mayusculas()
                    ->columnSpanFull(),

                Textarea::make('descripcion')
                    ->label('Descripción')
                    ->rows(3)
                    ->placeholder('ESPECIFICACIÓN, MARCA O PRESENTACIÓN (OPCIONAL)')
                    ->mayusculas()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function tabEstado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-power')
            ->schema([
                Toggle::make('activo')
                    ->label('Material activo')
                    ->default(true)
                    ->onColor('success')
                    ->offColor('danger')
                    ->helperText('Los materiales inactivos no aparecen al registrar entradas, compras ni requisiciones. El historial de inventario se preserva.')
                    ->columnSpanFull(),

                Toggle::make('exento_isv')
                    ->label('Exento de ISV')
                    ->default(false)
                    ->helperText('Producto exento por ley (no lleva ISV 15%). Al comprarlo, la línea se marca exenta automáticamente y el ISV de la factura se calcula solo sobre lo gravado.')
                    ->columnSpanFull(),

                Toggle::make('consumo_inmediato')
                    ->label('Consumo inmediato (no almacenable)')
                    ->default(false)
                    ->helperText('Para consumibles que no se guardan en bodega, como el agua de pipa: se compran con entrega directa a obra y el sistema los da por consumidos al recibirlos — el costo queda en la obra sin dejar stock fantasma. No se pueden comprar a bodega.')
                    ->columnSpanFull(),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        Placeholder::make('creado_at')
                            ->label('Creado')
                            ->content(fn (?Material $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),

                        Placeholder::make('items_vinculados')
                            ->label('Precios por zona vinculados')
                            ->content(function (?Material $record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                $count = $record->items()->count();

                                return $count === 1 ? '1 zona' : "{$count} zonas";
                            }),

                        Placeholder::make('cambios_registrados')
                            ->label('Cambios registrados')
                            ->content(function (?Material $record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                $count = $record->activities()->count();

                                return $count === 1 ? '1 cambio' : "{$count} cambios";
                            }),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
