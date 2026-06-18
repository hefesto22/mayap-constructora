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
                    ->collapsible()
                    ->collapsed()                       // ← ARRANCAN COLAPSADAS
                    ->collapseAllAction(
                        fn ($action) => $action->label('Contraer todo')
                    )
                    ->expandAllAction(
                        fn ($action) => $action->label('Expandir todo')
                    )
                    ->cloneable()
                    ->itemLabel(fn (array $state): HtmlString => self::etiquetaLineaCompacta($state))
                    ->schema(self::lineaSchema())
                    ->defaultItems(0)
                    ->minItems(0)
                    ->live(debounce: 500),

                self::seccionResumen(),
            ]);
    }

    /**
     * Schema compacto de cada línea: layout horizontal en grid de 12 columnas.
     * Cuando la línea está colapsada solo se muestra `etiquetaLineaCompacta`.
     * Al expandir, los campos van en filas densas (no apiladas verticalmente).
     *
     * @return array<int, Component>
     */
    private static function lineaSchema(): array
    {
        return [
            // ─── Fila 1: Tipo + Item / Descripción ─────────────────
            Grid::make(12)
                ->schema([
                    Select::make('tipo')
                        ->hiddenLabel()
                        ->placeholder('Tipo')
                        ->options(TipoLineaFicha::options())
                        ->default(TipoLineaFicha::Item->value)
                        ->required()
                        ->live()
                        ->native(false)
                        ->prefixIcon('heroicon-o-bars-3-bottom-left')
                        ->columnSpan(['default' => 12, 'md' => 3]),

                    // tipo='item': Item del catálogo
                    Select::make('item_id')
                        ->hiddenLabel()
                        ->placeholder('Selecciona item del catálogo…')
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
                        ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value)
                        ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value)
                        ->columnSpan(['default' => 12, 'md' => 9]),

                    // tipo='porcentaje': Descripción
                    TextInput::make('descripcion')
                        ->hiddenLabel()
                        ->placeholder('HERRAMIENTA MENOR / IMPREVISTOS / SUPERVISIÓN…')
                        ->maxLength(200)
                        ->live(debounce: 500)
                        ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                        ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                        ->mayusculas()
                        ->columnSpan(['default' => 12, 'md' => 9]),
                ]),

            // ─── Fila 2 (tipo=item): Rendimiento + Desperdicio + Subtotal en vivo ──
            Grid::make(12)
                ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value)
                ->schema([
                    TextInput::make('rendimiento')
                        ->label('Rendimiento efectivo')
                        ->numeric()
                        ->step(0.000001)
                        ->minValue(0)
                        ->live(debounce: 500)
                        ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value)
                        ->placeholder('0.892500')
                        ->columnSpan(['default' => 12, 'md' => 5])
                        ->helperText('Valor con la pérdida ya considerada (igual que Excel calcula internamente). Hasta 6 decimales.'),

                    TextInput::make('desperdicio_porcentaje')
                        ->label('Desperdicio')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(0)
                        ->live(debounce: 500)
                        ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Item->value)
                        ->suffix('%')
                        ->placeholder('5')
                        ->columnSpan(['default' => 6, 'md' => 3])
                        ->helperText('Solo informativo: documenta el % de pérdida que ya está dentro del rendimiento.'),

                    Placeholder::make('subtotal_linea')
                        ->label('Subtotal')
                        ->columnSpan(['default' => 6, 'md' => 4])
                        ->content(fn (Get $get): HtmlString => self::renderSubtotalCompacto($get)),
                ]),

            // ─── Fila 2 (tipo=porcentaje): % + base + destino ──────
            Grid::make(12)
                ->visible(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                ->schema([
                    TextInput::make('porcentaje')
                        ->label('% a aplicar')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(100)
                        ->live(debounce: 500)
                        ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                        ->suffix('%')
                        ->placeholder('5')
                        ->columnSpan(['default' => 4, 'md' => 2]),

                    Select::make('categoria_base')
                        ->label('Calcular sobre')
                        ->options(CategoriaBaseLinea::options())
                        ->live()
                        ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                        ->native(false)
                        ->columnSpan(['default' => 8, 'md' => 5]),

                    Select::make('categoria_destino')
                        ->label('Sección reporte')
                        ->options(CategoriaItem::options())
                        ->live()
                        ->required(fn (Get $get): bool => $get('tipo') === TipoLineaFicha::Porcentaje->value)
                        ->native(false)
                        ->columnSpan(['default' => 12, 'md' => 5]),
                ]),

            // ─── Notas (opcional, una sola línea) ──────────────────
            TextInput::make('notas')
                ->hiddenLabel()
                ->placeholder('Notas opcionales de esta línea')
                ->maxLength(500)
                ->mayusculas()
                ->columnSpanFull(),
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
     * Etiqueta enriquecida de cada línea cuando está COLAPSADA.
     * Muestra todo lo importante en UNA sola fila para que con 17+ líneas
     * no haya scroll infinito. Incluye el subtotal calculado al vuelo.
     *
     * @param array<string, mixed> $state
     */
    private static function etiquetaLineaCompacta(array $state): HtmlString
    {
        $tipo = $state['tipo'] ?? null;

        // Línea tipo PORCENTAJE
        if ($tipo === TipoLineaFicha::Porcentaje->value) {
            $desc = is_string($state['descripcion'] ?? null) ? $state['descripcion'] : 'Línea %';
            $pct = $state['porcentaje'] ?? '?';
            $base = $state['categoria_base'] ?? '?';
            $baseLabels = [
                'materiales'         => 'Materiales',
                'mano_obra'          => 'MO',
                'herramienta_equipo' => 'HE',
                'costo_directo'      => 'Costo Directo',
            ];
            $baseLabel = $baseLabels[$base] ?? $base;

            return new HtmlString(
                '<div class="flex items-center gap-2 text-sm">'
                .'<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">%</span>'
                .'<span class="font-medium">'.htmlspecialchars((string) $desc).'</span>'
                .'<span class="text-gray-500">·</span>'
                .'<span class="text-gray-600 dark:text-gray-300">'.$pct.'% sobre '.$baseLabel.'</span>'
                .'</div>'
            );
        }

        // Línea tipo ITEM
        $itemId = $state['item_id'] ?? null;
        $rendimiento = $state['rendimiento'] ?? null;
        $desperdicio = $state['desperdicio_porcentaje'] ?? '0';

        if ($itemId === null) {
            return new HtmlString('<span class="text-gray-400 italic text-sm">Línea nueva sin item — click para configurar</span>');
        }

        $item = Item::find($itemId);

        if ($item === null) {
            return new HtmlString('<span class="text-gray-400 italic text-sm">Item no encontrado</span>');
        }

        $subtotalFmt = '—';

        if ($rendimiento !== null && $rendimiento !== '') {
            $service = app(CalcularPrecioFichaService::class);
            $subtotal = $service->calcularLineaItem(
                (string) $rendimiento,
                (string) $desperdicio,
                (string) $item->precio_unitario,
            );
            $subtotalFmt = 'L '.number_format((float) $subtotal, 2);
        }

        $unidadCodigo = $item->unidadMedida->codigo;
        $rendFmt = $rendimiento !== null && $rendimiento !== ''
            ? rtrim(rtrim(number_format((float) $rendimiento, 6), '0'), '.')
            : '?';

        return new HtmlString(
            '<div class="flex items-center gap-2 text-sm">'
            .'<span class="font-mono text-xs text-gray-500">'.htmlspecialchars((string) $item->codigo).'</span>'
            .'<span class="font-medium truncate max-w-md">'.htmlspecialchars((string) $item->nombre).'</span>'
            .'<span class="text-gray-400">['.htmlspecialchars($unidadCodigo).']</span>'
            .'<span class="text-gray-500">·</span>'
            .'<span class="text-gray-600 dark:text-gray-300">rend '.$rendFmt.' / desp '.$desperdicio.'%</span>'
            .'<span class="text-gray-500">·</span>'
            .'<span class="font-bold text-emerald-600 dark:text-emerald-400 ml-auto">'.$subtotalFmt.'</span>'
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
                '<div class="text-center text-gray-500 py-6">'
                .'Agrega líneas a la ficha para ver el resumen en vivo.'
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

        $cards = [
            ['label' => 'Materiales',         'valor' => $resultado['subtotalesPorCategoria'][CategoriaItem::Materiales->value],        'color' => 'blue'],
            ['label' => 'Mano de obra',       'valor' => $resultado['subtotalesPorCategoria'][CategoriaItem::ManoObra->value],          'color' => 'green'],
            ['label' => 'Herram. y equipo',   'valor' => $resultado['subtotalesPorCategoria'][CategoriaItem::HerramientaEquipo->value], 'color' => 'amber'],
            ['label' => 'Indirectos',         'valor' => $resultado['subtotalesPorCategoria'][CategoriaItem::Indirectos->value],        'color' => 'gray'],
        ];

        $cardsHtml = '<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">';

        foreach ($cards as $card) {
            $cardsHtml .= sprintf(
                '<div class="rounded-lg border border-%s-200 dark:border-%s-800 bg-%s-50 dark:bg-%s-900/20 p-3">'
                .'<div class="text-xs uppercase tracking-wide text-%s-600 dark:text-%s-400">%s</div>'
                .'<div class="text-lg font-bold text-%s-700 dark:text-%s-300">L %s</div>'
                .'</div>',
                $card['color'],
                $card['color'],
                $card['color'],
                $card['color'],
                $card['color'],
                $card['color'],
                $card['label'],
                $card['color'],
                $card['color'],
                number_format((float) $card['valor'], 2)
            );
        }
        $cardsHtml .= '</div>';

        $subtotal = number_format((float) $resultado['subtotal'], 2);
        $utilFmt = number_format((float) $utilidad, 2);
        $utilMonto = number_format((float) $resultado['utilidadMonto'], 2);
        $precioVenta = number_format((float) $resultado['precioVenta'], 2);

        $totalesHtml = '<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">'
            .'<div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">'
            .'<div class="text-xs uppercase tracking-wide text-gray-500">Subtotal</div>'
            .'<div class="text-xl font-bold">L '.$subtotal.'</div>'
            .'</div>'
            .'<div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">'
            .'<div class="text-xs uppercase tracking-wide text-gray-500">Utilidad '.$utilFmt.'%</div>'
            .'<div class="text-xl font-bold">L '.$utilMonto.'</div>'
            .'</div>'
            .'<div class="rounded-lg border-2 border-emerald-500 bg-emerald-50 dark:bg-emerald-900/30 p-3">'
            .'<div class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300 font-semibold">Costo directo (Mat+MO+HE)</div>'
            .'<div class="text-xl font-bold text-emerald-700 dark:text-emerald-300">L '.number_format((float) $resultado['costoDirecto'], 2).'</div>'
            .'</div>'
            .'</div>';

        $bigNumberHtml = '<div class="rounded-xl border-4 border-emerald-600 bg-gradient-to-br from-emerald-50 to-emerald-100 dark:from-emerald-900/40 dark:to-emerald-800/40 p-6 text-center">'
            .'<div class="text-sm uppercase tracking-widest text-emerald-700 dark:text-emerald-300 font-semibold">Precio venta por '.htmlspecialchars($unidadCodigo).'</div>'
            .'<div class="text-5xl font-black text-emerald-700 dark:text-emerald-300 mt-2">L '.$precioVenta.'</div>'
            .'</div>';

        return new HtmlString($cardsHtml.$totalesHtml.$bigNumberHtml);
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
