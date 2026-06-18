<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\Zona;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Form de Proyecto/Cotización con 4 tabs.
 *
 * UX clave:
 *  - Zona INMUTABLE después de crear (los precios dependen de la zona).
 *  - Selector de fichas FILTRADO por zona del proyecto — el ingeniero
 *    nunca ve fichas de otras zonas.
 *  - Snapshot de precio se carga automáticamente al seleccionar ficha.
 *  - Cálculo en vivo del subtotal por renglón y totales del proyecto.
 *  - BIG NUMBER del total en la sección de Resumen.
 */
class ProyectoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('proyecto_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabIdentificacion(),
                    self::tabComposicion(),
                    self::tabResumen(),
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
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->helperText('Asignado al crear. Patrón: PROY-{AÑO}-{NÚMERO}.'),

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
                    ->helperText('La zona NO se puede cambiar después de crear el proyecto. Solo se podrán agregar fichas de esta zona.'),

                Select::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre', fn ($query) => $query->activos()->orderBy('nombre'))
                    ->getOptionLabelFromRecordUsing(fn (Cliente $record): string => $record->etiqueta)
                    ->required()
                    ->searchable(['codigo', 'nombre', 'rtn', 'email'])
                    ->preload()
                    ->prefixIcon('heroicon-o-user')
                    ->createOptionForm([
                        TextInput::make('nombre')
                            ->label('Nombre / Razón social')
                            ->required()
                            ->maxLength(255)
                            ->mayusculas(),
                        TextInput::make('rtn')
                            ->label('RTN')
                            ->maxLength(14)
                            ->mask('99999999999999')
                            ->rules(['nullable', 'regex:/^\d{14}$/']),
                        TextInput::make('telefono')->label('Teléfono')->tel(),
                        TextInput::make('email')->label('Email')->email(),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        return Cliente::create([...$data, 'activo' => true])->id;
                    }),

                TextInput::make('nombre')
                    ->label('Nombre del proyecto')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('CASA HABITACION 200M2 EN COLONIA LAS MERCEDES')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-building-office')
                    ->columnSpanFull(),

                Textarea::make('descripcion')
                    ->label('Descripción')
                    ->rows(2)
                    ->placeholder('OPCIONAL: ALCANCE, OBSERVACIONES TÉCNICAS')
                    ->mayusculas()
                    ->columnSpanFull(),

                Textarea::make('direccion_obra')
                    ->label('Dirección de la obra')
                    ->required()
                    ->rows(2)
                    ->placeholder('BARRIO/COLONIA, CALLE, REFERENCIA')
                    ->mayusculas()
                    ->columnSpanFull(),

                DatePicker::make('fecha_emision')
                    ->label('Fecha de emisión')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if ($state !== null) {
                            $set('fecha_validez', Carbon::parse($state)->addDays(30)->toDateString());
                        }
                    }),

                DatePicker::make('fecha_validez')
                    ->label('Válida hasta')
                    ->required()
                    ->default(now()->addDays(30))
                    ->native(false)
                    ->after('fecha_emision')
                    ->helperText('Después de esta fecha la cotización pasa automáticamente a estado Vencida.'),
            ])
            ->columns(2);
    }

    private static function tabComposicion(): Tab
    {
        return Tab::make('Composición')
            ->icon('heroicon-o-squares-plus')
            ->badge(fn (?Proyecto $record): ?int => $record?->renglones_count)
            ->schema([
                Repeater::make('renglones')
                    ->relationship()
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->reorderableWithDragAndDrop()
                    ->orderColumn('orden')
                    ->addActionLabel('+ Agregar renglón')
                    ->collapsible()
                    ->collapsed()
                    ->cloneable()
                    ->itemLabel(fn (array $state): HtmlString => self::etiquetaRenglonCompacta($state))
                    ->schema(self::renglonSchema())
                    ->defaultItems(0)
                    ->minItems(0)
                    ->live(debounce: 500)
                    ->disabled(fn (?Proyecto $record): bool => $record !== null && ! $record->estado->permiteEditar())
                    ->helperText(fn (?Proyecto $record): ?string => $record !== null && ! $record->estado->permiteEditar()
                        ? 'Este proyecto está en estado '.$record->estado->getLabel().'. Para editar renglones, duplica el proyecto.'
                        : null),
            ]);
    }

    /**
     * @return array<int, Component>
     */
    private static function renglonSchema(): array
    {
        return [
            Grid::make(12)
                ->schema([
                    TextInput::make('capitulo')
                        ->label('Capítulo')
                        ->maxLength(100)
                        ->placeholder('01 PRELIMINARES')
                        ->datalist(function (Get $get): array {
                            // Sugiere capítulos previos del mismo proyecto.
                            $renglones = $get('../../renglones') ?? [];

                            if (! is_array($renglones)) {
                                return [];
                            }

                            return collect($renglones)
                                ->pluck('capitulo')
                                ->filter()
                                ->unique()
                                ->values()
                                ->all();
                        })
                        ->mayusculas()
                        ->columnSpan(['default' => 12, 'md' => 4]),

                    Select::make('ficha_id')
                        ->label('Ficha APU')
                        ->options(function (Get $get): array {
                            $zonaId = $get('../../zona_id');

                            if ($zonaId === null) {
                                return [];
                            }

                            return Ficha::query()
                                ->where('zona_id', $zonaId)
                                ->where('activa', true)
                                ->with('unidadMedida:id,codigo')
                                ->orderBy('nombre')
                                ->get()
                                ->mapWithKeys(fn (Ficha $f): array => [
                                    $f->id => sprintf(
                                        '%s · %s [%s] · L %s',
                                        $f->codigo,
                                        $f->nombre,
                                        $f->unidadMedida->codigo,
                                        number_format((float) $f->precio_venta_cache, 2)
                                    ),
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set): void {
                            if ($state !== null) {
                                $ficha = Ficha::find($state);

                                if ($ficha !== null) {
                                    $set('precio_unitario_snapshot', (string) $ficha->precio_venta_cache);
                                }
                            }
                        })
                        ->columnSpan(['default' => 12, 'md' => 8]),
                ]),

            Grid::make(12)
                ->schema([
                    TextInput::make('cantidad')
                        ->label('Cantidad')
                        ->required()
                        ->numeric()
                        ->step(0.0001)
                        ->minValue(0.0001)
                        ->live(debounce: 500)
                        ->placeholder('120.5')
                        ->columnSpan(['default' => 4, 'md' => 3]),

                    TextInput::make('precio_unitario_snapshot')
                        ->label('Precio unitario (L)')
                        ->required()
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->live(debounce: 500)
                        ->prefix('L')
                        ->helperText('Snapshot al agregar. Cambios futuros en la ficha NO afectan este renglón.')
                        ->columnSpan(['default' => 4, 'md' => 4]),

                    Placeholder::make('subtotal_renglon_preview')
                        ->label('Subtotal')
                        ->columnSpan(['default' => 4, 'md' => 5])
                        ->content(fn (Get $get): HtmlString => self::renderSubtotalRenglon($get)),
                ]),

            TextInput::make('notas')
                ->label('Notas opcionales')
                ->maxLength(500)
                ->placeholder('Observaciones del renglón')
                ->mayusculas()
                ->columnSpanFull(),
        ];
    }

    private static function tabResumen(): Tab
    {
        return Tab::make('Resumen')
            ->icon('heroicon-o-calculator')
            ->schema([
                Section::make('Totales del proyecto')
                    ->schema([
                        Placeholder::make('resumen_en_vivo')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (Get $get): HtmlString => self::renderResumenProyecto($get)),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function tabEstado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-flag')
            ->schema([
                Placeholder::make('estado_actual_label')
                    ->label('Estado actual')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->content(fn (?Proyecto $record): HtmlString => $record !== null
                        ? new HtmlString(
                            '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-'
                            .$record->estado->getColor().'-100 text-'.$record->estado->getColor().'-800">'
                            .$record->estado->getLabel()
                            .'</span>'
                        )
                        : new HtmlString('—')),

                Toggle::make('aplica_isv')
                    ->label('Aplica ISV')
                    ->default(true)
                    ->live()
                    ->onColor('success')
                    ->offColor('warning')
                    ->afterStateUpdated(function ($state, Set $set): void {
                        // Si se desactiva ISV, forzar isv_porcentaje a 0
                        // para satisfacer el CHECK constraint.
                        if (! $state) {
                            $set('isv_porcentaje', 0);
                        } else {
                            $set('isv_porcentaje', 15);
                        }
                    })
                    ->helperText('Desactiva para clientes exentos (gobierno, ONG, etc.).'),

                TextInput::make('isv_porcentaje')
                    ->label('ISV %')
                    ->required()
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(15.00)
                    ->suffix('%')
                    ->disabled(fn (Get $get): bool => ! ($get('aplica_isv') ?? true))
                    ->dehydrated(),

                TextInput::make('moneda')
                    ->label('Moneda')
                    ->disabled()
                    ->dehydrated()
                    ->default('HNL')
                    ->helperText('Lempiras hondureños. Multi-moneda planeado para futuro Sprint.'),

                Textarea::make('notas')
                    ->label('Notas internas')
                    ->rows(3)
                    ->mayusculas()
                    ->columnSpanFull()
                    ->helperText('Solo visible para el equipo. NO aparece en el PDF al cliente.'),

                Section::make('Información del registro')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        Placeholder::make('precio_calculado_at_label')
                            ->label('Última recalculación')
                            ->content(function (?Proyecto $record): string {
                                if ($record?->precio_calculado_at === null) {
                                    return 'Nunca recalculado';
                                }

                                return $record->precio_calculado_at->diffForHumans()
                                    .' ('.$record->precio_calculado_at->format('d/m/Y H:i').')';
                            }),

                        Placeholder::make('creado_at')
                            ->label('Creado')
                            ->content(fn (?Proyecto $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),

                        Placeholder::make('actualizado_at')
                            ->label('Última modificación')
                            ->content(fn (?Proyecto $record): string => $record?->updated_at?->format('d/m/Y H:i') ?? '—'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * Subtotal del renglón en vivo: cantidad × precio.
     */
    private static function renderSubtotalRenglon(Get $get): HtmlString
    {
        $cantidad = (float) ($get('cantidad') ?? 0);
        $precio = (float) ($get('precio_unitario_snapshot') ?? 0);

        if ($cantidad <= 0 || $precio <= 0) {
            return new HtmlString('<span class="text-gray-400 text-sm">—</span>');
        }

        $subtotal = $cantidad * $precio;
        $fmt = 'L '.number_format($subtotal, 2);

        return new HtmlString(
            '<div class="text-sm">'
            .'<div class="font-bold text-emerald-600 dark:text-emerald-400 text-xl">'.$fmt.'</div>'
            .'<div class="text-xs text-gray-500">'.number_format($cantidad, 4).' × L '.number_format($precio, 2).'</div>'
            .'</div>'
        );
    }

    /**
     * Resumen completo del proyecto: subtotal + ISV + BIG NUMBER del total.
     */
    private static function renderResumenProyecto(Get $get): HtmlString
    {
        $renglones = $get('renglones') ?? [];
        $aplicaIsv = (bool) ($get('aplica_isv') ?? true);
        $isvPorcentaje = (float) ($get('isv_porcentaje') ?? 15);

        if (! is_array($renglones) || $renglones === []) {
            return new HtmlString(
                '<div class="text-center text-gray-500 py-6">'
                .'Agrega renglones en la pestaña Composición para ver el resumen.'
                .'</div>'
            );
        }

        $subtotal = 0.0;

        foreach ($renglones as $r) {
            $cantidad = (float) ($r['cantidad'] ?? 0);
            $precio = (float) ($r['precio_unitario_snapshot'] ?? 0);
            $subtotal += $cantidad * $precio;
        }

        $isv = $aplicaIsv ? round($subtotal * ($isvPorcentaje / 100), 2) : 0.0;
        $total = round($subtotal + $isv, 2);

        $totalesHtml = '<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">'
            .'<div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">'
            .'<div class="text-xs uppercase tracking-wide text-gray-500">Subtotal</div>'
            .'<div class="text-2xl font-bold">L '.number_format($subtotal, 2).'</div>'
            .'</div>'
            .'<div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">'
            .'<div class="text-xs uppercase tracking-wide text-gray-500">ISV '
            .($aplicaIsv ? number_format($isvPorcentaje, 2).'%' : '(EXENTO)').'</div>'
            .'<div class="text-2xl font-bold">L '.number_format($isv, 2).'</div>'
            .'</div>'
            .'</div>';

        $bigNumberHtml = '<div class="rounded-xl border-4 border-emerald-600 bg-gradient-to-br from-emerald-50 to-emerald-100 dark:from-emerald-900/40 dark:to-emerald-800/40 p-6 text-center">'
            .'<div class="text-sm uppercase tracking-widest text-emerald-700 dark:text-emerald-300 font-semibold">Total de la cotización</div>'
            .'<div class="text-5xl font-black text-emerald-700 dark:text-emerald-300 mt-2">L '.number_format($total, 2).'</div>'
            .'<div class="text-xs text-emerald-600 mt-2">Vista previa en vivo. Se persiste al guardar.</div>'
            .'</div>';

        return new HtmlString($totalesHtml.$bigNumberHtml);
    }

    /**
     * Etiqueta enriquecida del renglón cuando está colapsado.
     *
     * @param array<string, mixed> $state
     */
    private static function etiquetaRenglonCompacta(array $state): HtmlString
    {
        $fichaId = $state['ficha_id'] ?? null;
        $cantidad = $state['cantidad'] ?? null;
        $precio = $state['precio_unitario_snapshot'] ?? null;
        $capitulo = $state['capitulo'] ?? null;

        if ($fichaId === null) {
            return new HtmlString('<span class="text-gray-400 italic text-sm">Renglón nuevo — click para configurar</span>');
        }

        $ficha = Ficha::find($fichaId);

        if ($ficha === null) {
            return new HtmlString('<span class="text-gray-400 italic text-sm">Ficha no encontrada</span>');
        }

        $subtotalFmt = '—';

        if ($cantidad !== null && $cantidad !== '' && $precio !== null && $precio !== '') {
            $subtotal = (float) $cantidad * (float) $precio;
            $subtotalFmt = 'L '.number_format($subtotal, 2);
        }

        $unidad = $ficha->unidadMedida->codigo;
        $cantidadFmt = $cantidad !== null && $cantidad !== '' ? number_format((float) $cantidad, 2) : '?';
        $capituloHtml = $capitulo !== null && $capitulo !== ''
            ? '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">'.htmlspecialchars((string) $capitulo).'</span>'
            : '';

        return new HtmlString(
            '<div class="flex items-center gap-2 text-sm">'
            .$capituloHtml
            .'<span class="font-mono text-xs text-gray-500">'.htmlspecialchars((string) $ficha->codigo).'</span>'
            .'<span class="font-medium truncate max-w-md">'.htmlspecialchars((string) $ficha->nombre).'</span>'
            .'<span class="text-gray-500">·</span>'
            .'<span class="text-gray-600 dark:text-gray-300">'.$cantidadFmt.' '.htmlspecialchars($unidad).'</span>'
            .'<span class="text-gray-500">·</span>'
            .'<span class="font-bold text-emerald-600 dark:text-emerald-400 ml-auto">'.$subtotalFmt.'</span>'
            .'</div>'
        );
    }
}
