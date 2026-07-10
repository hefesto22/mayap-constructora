<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fichas\Schemas;

use App\Enums\CategoriaBaseLinea;
use App\Enums\CategoriaItem;
use App\Enums\TipoLineaFicha;
use App\Models\Ficha;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Fichas\CalcularDesdeStateFicha;
use App\Services\Fichas\CalcularPrecioFichaService;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Form de Ficha APU con cálculo EN VIVO (Sesión 3 del Sprint 2).
 *
 * MODELO DE CAPTURA (decisión Sesión 3): único, simple, alineado con Excel.
 *
 *  - El campo `Rendimiento` se captura como valor EFECTIVO (con la pérdida
 *    ya considerada). Acepta hasta 6 decimales — replica la precisión
 *    interna que Excel usa aunque solo muestre 3 decimales en pantalla.
 *
 *  - El campo `Desperdicio %` es metadato informativo: documenta de dónde
 *    proviene el rendimiento efectivo. NO se aplica al cálculo.
 *
 *  - Fórmula única: subtotal = rendimiento × precio_unitario.
 *
 * Tres pestañas:
 *  1. Identificación — qué obra unitaria es, zona, unidad de salida,
 *     parámetros técnicos descriptivos, utilidad %.
 *  2. Composición — repeater de líneas con cálculo en vivo + Section
 *     de resumen con totales y BIG NUMBER.
 *  3. Estado — toggle activa + cache + metadata histórica.
 */
class FichaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('ficha_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabIdentificacion(),
                    self::tabComposicion(),
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
                    ->live()
                    ->prefixIcon('heroicon-o-map-pin')
                    ->disabledOn('edit')
                    ->dehydrated()
                    ->helperText('Cada zona tiene su propia base de precios. La zona NO se puede cambiar después de crear la ficha.'),

                Select::make('unidad_medida_id')
                    ->label('Unidad de salida')
                    ->relationship('unidadMedida', 'nombre', fn ($query) => $query->activas()->orderBy('codigo'))
                    ->getOptionLabelFromRecordUsing(fn (UnidadMedida $record): string => $record->etiqueta)
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->prefixIcon('heroicon-o-scale')
                    ->helperText('Unidad de medida de lo que esta ficha produce: 1 M² de losa, 1 ML de tubería, 1 M³ de excavación, etc.'),

                TextInput::make('codigo')
                    ->label('Código del sistema')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->prefixIcon('heroicon-o-hashtag')
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->helperText('Asignado automáticamente al crear. Patrón: {ZONA}-APU-{NÚMERO}.'),

                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(300)
                    ->prefixIcon('heroicon-o-tag')
                    ->placeholder('LOSA DE CONCRETO ALIGERADA, E=10CM, 3000PSI VAR#4 @20CM A.S.')
                    ->mayusculas()
                    ->columnSpanFull()
                    ->helperText('Nombre técnico descriptivo. Soporta hasta 300 caracteres con acotaciones técnicas.'),

                Textarea::make('descripcion')
                    ->label('Descripción extendida')
                    ->rows(3)
                    ->placeholder('OPCIONAL: NOTAS TÉCNICAS ADICIONALES SOBRE LA OBRA UNITARIA')
                    ->mayusculas()
                    ->columnSpanFull(),

                TextInput::make('utilidad_porcentaje')
                    ->label('Utilidad %')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->default(25.00)
                    ->live(debounce: 500)
                    ->suffix('%')
                    ->prefixIcon('heroicon-o-arrow-trending-up')
                    ->helperText('Porcentaje de utilidad sobre el subtotal (mat + MO + HE + indirectos). Default 25%.'),

                KeyValue::make('parametros_tecnicos')
                    ->label('Parámetros técnicos')
                    ->keyLabel('Parámetro')
                    ->valueLabel('Valor')
                    ->keyPlaceholder('VOLUMEN DE CONCRETO')
                    ->valuePlaceholder('0.1 M³/M²')
                    ->reorderable()
                    ->addActionLabel('Agregar parámetro')
                    ->columnSpanFull()
                    ->helperText('Pares clave-valor descriptivos que aparecen en la cabecera del PDF. NO afectan los cálculos.'),
            ])
            ->columns(2);
    }

    private static function tabComposicion(): Tab
    {
        return Tab::make('Composición')
            ->icon('heroicon-o-squares-plus')
            ->badge(fn (?Ficha $record): ?int => $record?->lineas_count)
            ->schema([
                Repeater::make('lineas')
                    ->relationship()
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->reorderableWithDragAndDrop()
                    ->orderColumn('orden')
                    ->addActionLabel('+ Agregar línea')
                    ->cloneable()
                    ->table([
                        TableColumn::make('Tipo')->width('120px'),
                        TableColumn::make('Item / concepto'),
                        TableColumn::make('Rend. / %')->width('120px'),
                        TableColumn::make('Desp. / base')->width('130px'),
                        TableColumn::make('Subtotal / sección')->width('170px'),
                        TableColumn::make('Notas'),
                    ])
                    ->schema(self::lineaSchema())
                    ->defaultItems(1)                   // arranca con una línea lista para llenar
                    ->minItems(0)
                    ->live(debounce: 500),

                self::seccionResumen(),
            ]);
    }

    /**
     * Schema de cada línea en modo TABLA: cada entrada de primer nivel ocupa
     * una columna. Las columnas que cambian según el tipo envuelven los dos
     * campos (item / porcentaje) en un Grid de 1 columna con visibilidad
     * mutuamente excluyente. Al elegir un item se PRE-CARGA su desperdicio.
     *
     * @return array<int, Component>
     */
    private static function lineaSchema(): array
    {
        return [
            // 1 · Tipo
            Select::make('tipo')
                ->hiddenLabel()
                ->options(TipoLineaFicha::options())
                ->default(TipoLineaFicha::Item->value)
                ->selectablePlaceholder(false)
                ->required()
                ->live()
                ->native(false),

            // 2 · Item (item) / Descripción (porcentaje)
            Grid::make(1)->schema([
                Select::make('item_id')
                    ->hiddenLabel()
                    ->placeholder('Item del catálogo…')
                    ->options(function (Get $get): array {
                        $zonaId = $get('../../zona_id');

                        if ($zonaId === null) {
                            return [];
                        }

                        return Item::query()
                            ->where('zona_id', $zonaId)
                            ->where('activo', true)
                            ->with('unidadMedida:id,codigo')
                            ->orderBy('categoria')
                            ->orderBy('nombre')
                            ->get()
                            ->mapWithKeys(fn (Item $item): array => [
                                $item->id => sprintf(
                                    '%s · %s [%s] · L %s',
                                    $item->codigo,
                                    $item->nombre,
                                    $item->unidadMedida->codigo,
                                    number_format((float) $item->precio_unitario, 2)
                                ),
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        if ($state === null) {
                            return;
                        }

                        $item = Item::find($state);

                        if ($item !== null) {
                            // Pre-carga el desperdicio del item en la línea.
                            $set('desperdicio_porcentaje', (string) $item->desperdicio_porcentaje);
                        }
                    })
                    ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value)
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value),

                TextInput::make('descripcion')
                    ->hiddenLabel()
                    ->placeholder('Concepto (herramienta menor, imprevistos…)')
                    ->maxLength(200)
                    ->live(debounce: 500)
                    ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                    ->mayusculas(),
            ]),

            // 3 · Rendimiento (item) / % (porcentaje)
            Grid::make(1)->schema([
                TextInput::make('rendimiento')
                    ->hiddenLabel()
                    ->placeholder('Rend.')
                    ->numeric()
                    ->step(0.000001)
                    ->minValue(0)
                    ->live(debounce: 500)
                    ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value)
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value),

                TextInput::make('porcentaje')
                    ->hiddenLabel()
                    ->placeholder('%')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->live(debounce: 500)
                    ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value),
            ]),

            // 4 · Desperdicio (item) / Base de cálculo (porcentaje)
            Grid::make(1)->schema([
                TextInput::make('desperdicio_porcentaje')
                    ->hiddenLabel()
                    ->placeholder('Desp.')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(0)
                    ->suffix('%')
                    ->live(debounce: 500)
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value),

                Select::make('categoria_base')
                    ->hiddenLabel()
                    ->placeholder('Base')
                    ->options(CategoriaBaseLinea::options())
                    ->live()
                    ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                    ->native(false),
            ]),

            // 5 · Subtotal en vivo (item) / Sección del reporte (porcentaje)
            Grid::make(1)->schema([
                Placeholder::make('subtotal_linea')
                    ->hiddenLabel()
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value)
                    ->content(fn (Get $get): HtmlString => self::renderSubtotalCompacto($get)),

                Select::make('categoria_destino')
                    ->hiddenLabel()
                    ->placeholder('Sección')
                    ->options(CategoriaItem::options())
                    ->live()
                    ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                    ->native(false),
            ]),

            // 6 · Notas (opcional)
            TextInput::make('notas')
                ->hiddenLabel()
                ->placeholder('Notas')
                ->maxLength(500)
                ->mayusculas(),
        ];
    }

    /**
     * Subtotal compacto en una sola línea para mostrar en el grid de la línea.
     * Muestra L XX.XX en grande y debajo el desglose `rendimiento × precio`.
     */
    private static function renderSubtotalCompacto(Get $get): HtmlString
    {
        $itemId = $get('item_id');
        $rendimiento = $get('rendimiento');
        $desperdicio = $get('desperdicio_porcentaje') ?? '0';

        if ($itemId === null || $rendimiento === null || $rendimiento === '') {
            return new HtmlString('<span class="text-gray-400 text-sm">—</span>');
        }

        $item = Item::find($itemId);

        if ($item === null) {
            return new HtmlString('<span class="text-gray-400 text-sm">—</span>');
        }

        $service = app(CalcularPrecioFichaService::class);

        $subtotal = $service->calcularLineaItem(
            (string) $rendimiento,
            (string) $desperdicio,
            (string) $item->precio_unitario,
        );

        $rendFmt = rtrim(rtrim(number_format((float) $rendimiento, 6), '0'), '.');
        $precioFmt = number_format((float) $item->precio_unitario, 2);
        $subtotalFmt = 'L '.number_format((float) $subtotal, 2);

        return new HtmlString(
            '<div class="text-sm">'
            .'<div class="font-bold text-emerald-600 dark:text-emerald-400">'.$subtotalFmt.'</div>'
            .'<div class="text-xs text-gray-500">'.$rendFmt.' × L '.$precioFmt.'</div>'
            .'</div>'
        );
    }

    /**
     * Section "Resumen de la ficha" — cards con totales en vivo + BIG NUMBER.
     */
    private static function seccionResumen(): Section
    {
        return Section::make('Resumen de la ficha')
            ->icon('heroicon-o-calculator')
            ->columnSpanFull()
            ->schema([
                Placeholder::make('resumen_en_vivo')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(function (Get $get): HtmlString {
                        return self::renderResumenFicha($get);
                    }),
            ])
            ->collapsible()
            ->collapsed(false)
            ->persistCollapsed();
    }

    /**
     * Renderiza el resumen completo: 4 cards de subtotales por categoría +
     * card de subtotal global + utilidad + BIG NUMBER del precio venta.
     */
    private static function renderResumenFicha(Get $get): HtmlString
    {
        $lineas = $get('lineas') ?? [];
        $utilidad = $get('utilidad_porcentaje') ?? 25;

        if (! is_array($lineas) || $lineas === []) {
            return new HtmlString(
                '<div style="text-align:center;opacity:0.6;padding:24px 0;">'
                .'Agregá líneas a la ficha para ver el resumen en vivo.'
                .'</div>'
            );
        }

        $resultado = app(CalcularDesdeStateFicha::class)->calcular($lineas, $utilidad);

        $unidadId = $get('unidad_medida_id');
        $unidadCodigo = 'unidad';

        if ($unidadId !== null) {
            $unidad = UnidadMedida::find($unidadId);

            if ($unidad !== null) {
                $unidadCodigo = $unidad->codigo;
            }
        }

        // Estilos INLINE (no clases Tailwind): Filament no compila utilidades
        // arbitrarias en el panel, por eso el resumen anterior salía sin
        // estilo. Inline funciona igual en tema claro y oscuro.
        $borde = '1px solid rgba(120,120,120,0.30)';
        $celdaIzq = "padding:7px 12px;border:{$borde};text-align:left;";
        $celdaDer = "padding:7px 12px;border:{$borde};text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;";

        $filas = [
            ['Materiales',            $resultado['subtotalesPorCategoria'][CategoriaItem::Materiales->value]],
            ['Mano de obra',          $resultado['subtotalesPorCategoria'][CategoriaItem::ManoObra->value]],
            ['Herramienta y equipo',  $resultado['subtotalesPorCategoria'][CategoriaItem::HerramientaEquipo->value]],
            ['Indirectos',            $resultado['subtotalesPorCategoria'][CategoriaItem::Indirectos->value]],
        ];

        $filasHtml = '';

        foreach ($filas as [$label, $valor]) {
            $filasHtml .= '<tr>'
                ."<td style=\"{$celdaIzq}\">".htmlspecialchars($label).'</td>'
                ."<td style=\"{$celdaDer}\">L ".number_format((float) $valor, 2).'</td>'
                .'</tr>';
        }

        $costoDirecto = number_format((float) $resultado['costoDirecto'], 2);
        $subtotal = number_format((float) $resultado['subtotal'], 2);
        $utilFmt = number_format((float) $utilidad, 2);
        $utilMonto = number_format((float) $resultado['utilidadMonto'], 2);
        $precioVenta = number_format((float) $resultado['precioVenta'], 2);

        $tabla = '<div style="overflow-x:auto;">'
            .'<table style="width:100%;border-collapse:collapse;font-size:0.875rem;color:inherit;">'
            .'<thead><tr style="background:rgba(120,120,120,0.14);">'
            ."<th style=\"{$celdaIzq}font-weight:700;\">Concepto</th>"
            ."<th style=\"{$celdaDer}font-weight:700;\">Monto</th>"
            .'</tr></thead><tbody>'
            .$filasHtml
            .'<tr style="background:rgba(120,120,120,0.07);font-weight:700;">'
            ."<td style=\"{$celdaIzq}\">Costo directo (Mat + MO + HE)</td>"
            ."<td style=\"{$celdaDer}\">L {$costoDirecto}</td></tr>"
            .'<tr style="font-weight:700;">'
            ."<td style=\"{$celdaIzq}\">Subtotal (incluye indirectos)</td>"
            ."<td style=\"{$celdaDer}\">L {$subtotal}</td></tr>"
            ."<tr><td style=\"{$celdaIzq}\">Utilidad {$utilFmt}%</td>"
            ."<td style=\"{$celdaDer}\">L {$utilMonto}</td></tr>"
            .'</tbody></table></div>';

        $precioBox = '<div style="margin-top:14px;border:2px solid #059669;border-radius:12px;background:rgba(5,150,105,0.10);padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">'
            .'<span style="text-transform:uppercase;letter-spacing:0.08em;font-size:0.75rem;font-weight:700;color:#059669;">Precio de venta por '.htmlspecialchars($unidadCodigo).'</span>'
            .'<span style="font-size:2rem;font-weight:800;color:#059669;font-variant-numeric:tabular-nums;">L '.$precioVenta.'</span>'
            .'</div>';

        return new HtmlString($tabla.$precioBox);
    }

    private static function tabEstado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-power')
            ->schema([
                Toggle::make('activa')
                    ->label('Ficha activa')
                    ->default(true)
                    ->onColor('success')
                    ->offColor('danger')
                    ->helperText('Fichas inactivas no aparecen al armar presupuestos.')
                    ->columnSpanFull(),

                Section::make('Cache de cálculo')
                    ->icon('heroicon-o-calculator')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        Placeholder::make('subtotal_cache_label')
                            ->label('Subtotal (mat + MO + HE + ind)')
                            ->content(fn (?Ficha $record): string => $record !== null
                                ? 'L '.number_format((float) $record->subtotal_cache, 2)
                                : '—'),

                        Placeholder::make('precio_venta_cache_label')
                            ->label('Precio venta')
                            ->content(fn (?Ficha $record): string => $record !== null
                                ? 'L '.number_format((float) $record->precio_venta_cache, 2)
                                : '—'),

                        Placeholder::make('precio_calculado_at_label')
                            ->label('Última recalculación')
                            ->content(function (?Ficha $record): string {
                                if ($record?->precio_calculado_at === null) {
                                    return 'Nunca recalculada';
                                }

                                return $record->precio_calculado_at->diffForHumans()
                                    .' ('.$record->precio_calculado_at->format('d/m/Y H:i').')';
                            }),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        Placeholder::make('lineas_count_label')
                            ->label('Líneas de composición')
                            ->content(function (?Ficha $record): string {
                                if ($record === null) {
                                    return '—';
                                }
                                $count = $record->lineas()->count();

                                return $count === 1 ? '1 línea' : "{$count} líneas";
                            }),

                        Placeholder::make('creado_at')
                            ->label('Creada')
                            ->content(fn (?Ficha $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),

                        Placeholder::make('actualizado_at')
                            ->label('Última modificación')
                            ->content(fn (?Ficha $record): string => $record?->updated_at?->format('d/m/Y H:i') ?? '—'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
