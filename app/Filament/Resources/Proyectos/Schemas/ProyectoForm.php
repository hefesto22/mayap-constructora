<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Enums\EstadoProyecto;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Zona;
use App\Services\Requisiciones\PresupuestoMaterial;
use App\Services\Requisiciones\PresupuestoMaterialesProyectoService;
use App\Support\Roles;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                    self::tabResumen(),
                    self::tabEjecucion(),
                    self::tabControlMateriales(),
                    self::tabActividades(),
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

                Select::make('encargados')
                    ->label('Encargados de obra')
                    ->multiple()
                    ->relationship(
                        'encargados',
                        'name',
                        fn ($query) => $query
                            ->whereHas('roles', fn ($q) => $q->where('name', Roles::ENCARGADO_OBRA))
                            ->orderBy('name'),
                    )
                    ->searchable()
                    ->preload()
                    ->prefixIcon('heroicon-o-user-circle')
                    ->helperText('Responsables en campo: piden material para esta obra y confirman lo que llega. Solo ven SUS obras. Varios para cubrir ausencias.'),

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
                            ->content(fn (?Proyecto $record): HtmlString => self::renderResumenProyecto($record)),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function tabEjecucion(): Tab
    {
        return Tab::make('Ejecución')
            ->icon('heroicon-o-play-circle')
            ->schema([
                Section::make('Ejecución de la obra')
                    ->description('Plazo, anticipo y avance. Las acciones (Iniciar, Pausar, Finalizar, Cancelar) están en los botones de la cabecera.')
                    ->schema([
                        Placeholder::make('panel_ejecucion')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (?Proyecto $record): HtmlString => self::renderPanelEjecucion($record)),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Control presupuestario de materiales: qué dicen las fichas que se
     * necesita vs qué se ha pedido en requisiciones. Los excesos y los
     * materiales fuera de presupuesto se marcan en rojo/ámbar — aquí es
     * donde el dueño ve las fugas de la obra.
     */
    private static function tabControlMateriales(): Tab
    {
        return Tab::make('Control de materiales')
            ->icon('heroicon-o-cube')
            ->visible(fn (?Proyecto $record): bool => $record !== null)
            ->badge(function (?Proyecto $record): ?string {
                if ($record === null) {
                    return null;
                }

                $excedidos = app(PresupuestoMaterialesProyectoService::class)
                    ->porProyecto($record->id)
                    ->filter(fn (PresupuestoMaterial $pm): bool => $pm->excedido())
                    ->count();

                return $excedidos > 0 ? (string) $excedidos : null;
            })
            ->badgeColor('danger')
            ->schema([
                Section::make('Presupuesto vs pedidos')
                    ->description('Cantidades según las fichas de la obra contra lo comprometido en requisiciones.')
                    ->schema([
                        Placeholder::make('control_materiales')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (?Proyecto $record): HtmlString => new HtmlString(
                                view('filament.proyectos.control-materiales', [
                                    'filas' => $record !== null
                                        ? app(PresupuestoMaterialesProyectoService::class)->porProyecto($record->id)
                                        : collect(),
                                ])->render()
                            )),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function tabActividades(): Tab
    {
        return Tab::make('Actividades')
            ->icon('heroicon-o-check-circle')
            ->badge(fn (?Proyecto $record): ?string => $record !== null
                ? $record->avance_fisico_cache.'%'
                : null)
            ->schema([
                Placeholder::make('avance_preview')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(fn (Get $get): HtmlString => self::renderAvancePreview($get)),

                Repeater::make('actividades')
                    ->relationship()
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->table([
                        TableColumn::make('Actividad'),
                        TableColumn::make('Peso %')->width('120px'),
                        TableColumn::make('Completada')->width('130px'),
                    ])
                    ->reorderableWithDragAndDrop()
                    ->orderColumn('orden')
                    ->addActionLabel('+ Agregar actividad')
                    ->defaultItems(0)
                    ->minItems(0)
                    ->live()
                    ->schema([
                        TextInput::make('nombre')
                            ->hiddenLabel()
                            ->required()
                            ->maxLength(255)
                            ->placeholder('ARRANQUE PLANTEL')
                            ->mayusculas(),

                        TextInput::make('peso')
                            ->hiddenLabel()
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->suffix('%')
                            ->placeholder('Opcional'),

                        Toggle::make('completada')
                            ->hiddenLabel()
                            ->inline(false),
                    ])
                    ->helperText('Marcá las actividades completadas. El % de avance se calcula solo. El "Peso" es opcional: si lo dejás vacío, todas valen igual.'),
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
                    ->content(function (?Proyecto $record): HtmlString {
                        if ($record === null) {
                            return new HtmlString('—');
                        }

                        // Inline (el CSS del panel no compila clases custom).
                        $hex = match ($record->estado->getColor()) {
                            'success' => '#10b981',
                            'info'    => '#0ea5e9',
                            'warning' => '#f59e0b',
                            'danger'  => '#ef4444',
                            'primary' => '#6366f1',
                            default   => '#9ca3af',
                        };

                        return new HtmlString(
                            '<span style="display:inline-flex; align-items:center; padding:4px 14px; border-radius:9999px; '
                            .'font-size:.875rem; font-weight:600; color:'.$hex.'; background:'.$hex.'1a; border:1px solid '.$hex.'66;">'
                            .$record->estado->getLabel()
                            .'</span>'
                        );
                    }),

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
     * Resumen del proyecto: lee los totales YA persistidos (se recalculan
     * al agregar/editar renglones en la tabla de Composición y al guardar).
     */
    private static function renderResumenProyecto(?Proyecto $record): HtmlString
    {
        // NOTA DE ESTILOS: HTML dentro del panel Filament — su CSS compilado
        // NO incluye clases Tailwind arbitrarias, por eso todo va inline
        // (colores translúcidos funcionan en tema claro y oscuro).
        if ($record === null || $record->renglones()->count() === 0) {
            return new HtmlString(
                '<div style="text-align:center; opacity:.55; padding:24px 0;">'
                .'Guardá el proyecto y agregá renglones en la tabla de Composición (abajo) para ver el resumen.'
                .'</div>'
            );
        }

        $subtotal = (float) $record->subtotal_cache;
        $isv = (float) $record->isv_cache;
        $total = (float) $record->total_cache;
        $aplicaIsv = $record->aplica_isv;
        $isvPorcentaje = (float) $record->isv_porcentaje;

        $totalesHtml = '<div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-bottom:16px;">'
            .self::dato('Subtotal', 'L '.number_format($subtotal, 2), grande: true)
            .self::dato('ISV '.($aplicaIsv ? number_format($isvPorcentaje, 2).'%' : '(EXENTO)'), 'L '.number_format($isv, 2), grande: true)
            .'</div>';

        $bigNumberHtml = '<div style="border:3px solid #059669; border-radius:16px; background:rgba(16,185,129,0.08); padding:28px; text-align:center;">'
            .'<div style="font-size:.8rem; text-transform:uppercase; letter-spacing:.15em; color:#10b981; font-weight:600;">Total de la cotización</div>'
            .'<div style="font-size:3rem; line-height:1.1; font-weight:900; color:#10b981; margin-top:8px;">L '.number_format($total, 2).'</div>'
            .'<div style="font-size:.75rem; opacity:.55; margin-top:10px;">Se actualiza al cambiar renglones y al guardar.</div>'
            .'</div>';

        return new HtmlString($totalesHtml.$bigNumberHtml);
    }

    /**
     * Panel de ejecución: plazo, fechas, reloj de avance de tiempo,
     * barra de avance físico y anticipo. Se adapta al estado del proyecto.
     */
    private static function renderPanelEjecucion(?Proyecto $record): HtmlString
    {
        if ($record === null || $record->fecha_inicio === null) {
            $mensaje = $record !== null && $record->estado === EstadoProyecto::Aprobada
                ? 'Proyecto aprobado y listo para arrancar. Usá el botón <strong>"Iniciar proyecto"</strong> en la cabecera para definir la fecha de inicio y el plazo.'
                : 'La ejecución se habilita cuando el proyecto está <strong>Aprobado</strong>. Primero envialo y registrá la aprobación del cliente.';

            return new HtmlString(
                '<div style="border:1px dashed rgba(128,128,128,0.45); border-radius:12px; padding:28px; text-align:center; opacity:.7;">'
                .$mensaje.self::renderAnticipoBloque($record).'</div>'
            );
        }

        $modo = $record->modo_plazo?->getLabel() ?? '—';
        $diasTrans = $record->diasTranscurridos() ?? 0;
        $diasRest = $record->diasRestantes() ?? 0;
        $pctTiempo = $record->porcentajeTiempo() ?? 0.0;
        $avance = (float) $record->avance_fisico_cache;
        $atrasado = $record->estaAtrasado();

        $restanteTxt = $diasRest >= 0
            ? '<span style="font-weight:700; color:#10b981;">'.$diasRest.' días restantes</span>'
            : '<span style="font-weight:700; color:#ef4444;">'.abs($diasRest).' días de atraso</span>';

        $fechas = '<div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px;">'
            .self::dato('Inicio', $record->fecha_inicio->format('d/M/Y'))
            .self::dato('Fin estimado', $record->fecha_fin_estimada?->format('d/M/Y') ?? '—')
            .self::dato('Plazo', $record->plazo_dias.' días · '.$modo)
            .self::dato('Fin real', $record->fecha_fin_real?->format('d/M/Y') ?? '—')
            .'</div>';

        $alerta = $atrasado
            ? '<div style="border:1px solid rgba(239,68,68,0.4); border-radius:10px; background:rgba(239,68,68,0.08); color:#ef4444; font-size:.875rem; font-weight:500; padding:10px 14px; margin-bottom:14px;">⚠️ Obra atrasada: pasó la fecha estimada y el avance no llegó al 100%.</div>'
            : '';

        $barras = self::renderBarra('Avance de tiempo ('.$diasTrans.' días) — '.$restanteTxt, $pctTiempo, $atrasado ? '#ef4444' : '#0ea5e9')
            .self::renderBarra('Avance físico de obra', $avance, '#10b981');

        return new HtmlString($alerta.$fechas.$barras.self::renderAnticipoBloque($record));
    }

    /**
     * Vista previa en vivo del % de avance físico calculado desde las
     * actividades del formulario (peso vacío = peso 1).
     */
    private static function renderAvancePreview(Get $get): HtmlString
    {
        $actividades = $get('actividades') ?? [];

        if (! is_array($actividades) || $actividades === []) {
            return new HtmlString(
                '<div style="text-align:center; opacity:.55; padding:16px 0;">Agregá actividades para llevar el avance de la obra.</div>'
            );
        }

        $total = 0.0;
        $completadas = 0.0;

        foreach ($actividades as $a) {
            $peso = ($a['peso'] ?? null) !== null && $a['peso'] !== '' ? (float) $a['peso'] : 1.0;
            $total += $peso;

            if (! empty($a['completada'])) {
                $completadas += $peso;
            }
        }

        $pct = $total > 0 ? round(($completadas / $total) * 100, 2) : 0.0;

        return new HtmlString(self::renderBarra('Avance físico (vista previa)', $pct, '#10b981'));
    }

    /**
     * Bloque resumido del anticipo del cliente.
     */
    private static function renderAnticipoBloque(?Proyecto $record): string
    {
        if ($record === null || ! $record->anticipo_recibido) {
            return '<div style="margin-top:16px; font-size:.875rem; opacity:.55;">Sin anticipo registrado.</div>';
        }

        return '<div style="margin-top:16px; border:1px solid rgba(16,185,129,0.4); border-radius:10px; background:rgba(16,185,129,0.08); padding:12px 16px;">'
            .'<span style="font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; color:#10b981;">Anticipo recibido</span>'
            .'<div style="font-size:1.25rem; font-weight:700; color:#10b981;">L '.number_format((float) $record->anticipo_monto, 2).'</div>'
            .'<div style="font-size:.75rem; opacity:.55;">'.($record->anticipo_fecha?->format('d/M/Y') ?? '').'</div>'
            .'</div>';
    }

    /**
     * Tarjeta de dato (label pequeño arriba, valor abajo). $grande usa
     * tipografía de destacado para los totales del Resumen.
     */
    private static function dato(string $label, string $valor, bool $grande = false): string
    {
        $tamanoValor = $grande ? '1.5rem' : '.9rem';

        return '<div style="border:1px solid rgba(128,128,128,0.25); border-radius:10px; padding:12px 16px;">'
            .'<div style="font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; opacity:.55;">'.htmlspecialchars($label).'</div>'
            .'<div style="font-size:'.$tamanoValor.'; font-weight:700; margin-top:2px;">'.htmlspecialchars($valor).'</div>'
            .'</div>';
    }

    /**
     * Barra de progreso. El texto del label puede contener HTML (badges).
     */
    private static function renderBarra(string $labelHtml, float $pct, string $colorHex): string
    {
        $ancho = max(0.0, min(100.0, $pct));

        return '<div style="margin-bottom:14px;">'
            .'<div style="display:flex; justify-content:space-between; align-items:center; font-size:.875rem; margin-bottom:6px;">'
            .'<span>'.$labelHtml.'</span>'
            .'<span style="font-weight:700;">'.number_format($pct, 2).'%</span></div>'
            .'<div style="width:100%; height:10px; border-radius:9999px; background:rgba(128,128,128,0.2); overflow:hidden;">'
            .'<div style="width:'.$ancho.'%; height:100%; border-radius:9999px; background:'.$colorHex.';"></div>'
            .'</div></div>';
    }
}
