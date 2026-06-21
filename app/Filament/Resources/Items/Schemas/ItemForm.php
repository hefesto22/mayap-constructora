<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\Material;
use App\Models\UnidadMedida;
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

/**
 * Form de Item rediseñado con Tabs (Filament v4).
 *
 * Tres pestañas independientes que reflejan el modelo mental del
 * usuario al cargar un item:
 *  1. Identificación — qué es y dónde aplica.
 *  2. Precio y unidad — cuánto cuesta y cómo se mide.
 *  3. Estado — disponibilidad + metadata histórica.
 *
 * Campos de texto del dominio usan el macro ->mayusculas() registrado
 * en AppServiceProvider — aplica CSS visual + dehydrate uppercase
 * en una sola línea. Ver feedback_patron_diseno_filament.md.
 */
class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('item_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabIdentificacion(),
                    self::tabPrecio(),
                    self::tabEstado(),
                ]),
        ]);
    }

    private static function tabIdentificacion(): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-identification')
            ->schema([
                Select::make('zona_id')
                    ->label('Zona')
                    ->relationship('zona', 'nombre', fn ($query) => $query->activas()->orderBy('nombre'))
                    ->getOptionLabelFromRecordUsing(fn (Zona $record): string => $record->etiqueta)
                    ->required()
                    ->searchable()
                    ->preload()
                    ->prefixIcon('heroicon-o-map-pin')
                    ->disabledOn('edit')
                    ->dehydrated()
                    ->helperText('Cada zona tiene su propia base de precios. La zona NO se puede cambiar después de crear el item.'),

                Select::make('categoria')
                    ->label('Categoría')
                    ->options(CategoriaItem::options())
                    ->required()
                    ->native(false)
                    ->prefixIcon('heroicon-o-squares-2x2')
                    ->disabledOn('edit')
                    ->dehydrated()
                    ->helperText('Materiales / Mano de obra / Herramienta y equipo / Indirectos. NO editable después de crear.'),

                Select::make('material_id')
                    ->label('Material físico (inventario)')
                    ->relationship('material', 'nombre', fn ($query) => $query->where('activo', true)->orderBy('nombre'))
                    ->getOptionLabelFromRecordUsing(fn (Material $record): string => "{$record->codigo} — {$record->nombre}")
                    ->searchable(['codigo', 'nombre'])
                    ->preload()
                    ->prefixIcon('heroicon-o-cube')
                    ->helperText('Vincula este precio de venta con el material físico de inventario. Solo para materiales y herramienta; déjalo vacío en mano de obra e indirectos.'),

                TextInput::make('codigo')
                    ->label('Código del sistema')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->prefixIcon('heroicon-o-hashtag')
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->helperText('Asignado automáticamente al crear. Patrón: {ZONA}-{CATEGORÍA}-{NÚMERO}.'),

                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(200)
                    ->prefixIcon('heroicon-o-tag')
                    ->placeholder('CEMENTO GRIS SACO 50KG ARGOS')
                    ->mayusculas()
                    ->columnSpanFull(),

                Textarea::make('descripcion')
                    ->label('Descripción')
                    ->rows(3)
                    ->placeholder('DETALLE OPCIONAL SOBRE QUÉ ES Y CÓMO SE USA')
                    ->mayusculas()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function tabPrecio(): Tab
    {
        return Tab::make('Precio y unidad')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Select::make('unidad_medida_id')
                    ->label('Unidad de medida')
                    ->relationship('unidadMedida', 'nombre', fn ($query) => $query->activas()->orderBy('codigo'))
                    ->getOptionLabelFromRecordUsing(fn (UnidadMedida $record): string => $record->etiqueta)
                    ->required()
                    ->searchable()
                    ->preload()
                    ->prefixIcon('heroicon-o-scale')
                    ->createOptionForm([
                        TextInput::make('codigo')
                            ->label('Código')
                            ->required()
                            ->maxLength(20)
                            ->unique('unidades_medida', 'codigo')
                            ->mayusculas(),
                        TextInput::make('nombre')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(80)
                            ->mayusculas(),
                        TextInput::make('simbolo')
                            ->label('Símbolo')
                            ->maxLength(10)
                            ->helperText('Símbolo NO se uppercase: m², kg, ml mantienen su forma original.'),
                    ])
                    ->createOptionUsing(fn (array $data): int => UnidadMedida::create([
                        'codigo'  => $data['codigo'],
                        'nombre'  => $data['nombre'],
                        'simbolo' => $data['simbolo'] ?? null,
                        'activo'  => true,
                    ])->id),

                TextInput::make('precio_unitario')
                    ->label('Precio unitario')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(0)
                    ->prefix('L.')
                    ->helperText('Precio en lempiras por unidad. La fecha de actualización se registra automáticamente al cambiarlo.'),

                Textarea::make('observaciones_precio')
                    ->label('Observaciones sobre el precio')
                    ->rows(3)
                    ->placeholder('EJ: INCLUYE FLETE A OBRA, DESCUENTO POR VOLUMEN >50, SUBIÓ POR ESCASEZ EN FEBRERO')
                    ->mayusculas()
                    ->helperText('Opcional. Contexto que ayuda a entender por qué este precio es lo que es.')
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
                    ->label('Item activo')
                    ->default(true)
                    ->onColor('success')
                    ->offColor('danger')
                    ->helperText('Items inactivos no aparecen al armar fichas o presupuestos. Los snapshots históricos se preservan.')
                    ->columnSpanFull(),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        Placeholder::make('creado_at')
                            ->label('Creado')
                            ->content(fn (?Item $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),

                        Placeholder::make('precio_actualizado_at_label')
                            ->label('Última actualización de precio')
                            ->content(function (?Item $record): string {
                                if ($record?->precio_actualizado_at === null) {
                                    return 'Nunca';
                                }

                                return $record->precio_actualizado_at->diffForHumans()
                                    .' ('.$record->precio_actualizado_at->format('d/m/Y H:i').')';
                            }),

                        Placeholder::make('cambios_registrados')
                            ->label('Cambios registrados')
                            ->content(function (?Item $record): string {
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
