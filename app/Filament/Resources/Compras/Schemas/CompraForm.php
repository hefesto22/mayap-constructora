<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Schemas;

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Enums\EstadoProyecto;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Material;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\User;
use App\Services\Requisiciones\PresupuestoMaterialesProyectoService;
use App\Support\Cantidad;
use App\Support\Permisos;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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

    /**
     * Atajo "Registrar compra" desde una requisición (?requisicion={id}):
     * las líneas nacen con los materiales y cantidades FALTANTES
     * (autorizado − ya despachado) — recepción solo captura el precio de
     * factura. Sin parámetro (o sin faltantes): una fila vacía normal.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lineasDesdeRequisicion(): array
    {
        $filaVacia = [[]];

        $requisicionId = request()->integer('requisicion');

        if ($requisicionId <= 0) {
            return $filaVacia;
        }

        $requisicion = Requisicion::query()
            ->with('lineas.material:id,exento_isv')
            ->find($requisicionId);

        if ($requisicion === null) {
            return $filaVacia;
        }

        $lineas = $requisicion->lineas
            ->map(function ($linea): ?array {
                $autorizada = (string) ($linea->cantidad_autorizada ?? $linea->cantidad_solicitada);
                $faltante = bcsub($autorizada, (string) $linea->cantidad_despachada, 4);

                if (bccomp($faltante, '0', 4) <= 0) {
                    return null;
                }

                return [
                    'material_id' => $linea->material_id,
                    // Editable: sin ceros de cola ("12.0000" → "12"), sin
                    // redondear — la BD conserva la precisión completa.
                    'cantidad'       => Cantidad::sinCeros($faltante),
                    'costo_unitario' => null,
                    // La herencia del exento vive en afterStateUpdated del
                    // select — el prellenado no lo dispara, se setea aquí.
                    'exento' => (bool) $linea->material->exento_isv,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return $lineas === [] ? $filaVacia : $lineas;
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
                    ->disabled(fn (?Compra $record): bool => self::compraBloqueada($record)),

                Radio::make('destino_tipo')
                    ->label('Entrega en')
                    ->options([
                        'bodega' => 'Bodega (reabastecer inventario)',
                        'obra'   => 'Directo a obra (de la ferretería a la obra)',
                    ])
                    ->default('bodega')
                    ->live()
                    ->dehydrated(false)
                    // En edición se deriva del registro guardado.
                    ->afterStateHydrated(function (Radio $component, ?Compra $record): void {
                        if ($record !== null) {
                            $component->state($record->esDirectaAObra() ? 'obra' : 'bodega');
                        }
                    })
                    ->disabled(fn (?Compra $record): bool => self::compraBloqueada($record))
                    ->columnSpanFull(),

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
                    ->visible(fn (callable $get): bool => $get('destino_tipo') !== 'obra')
                    ->required(fn (callable $get): bool => $get('destino_tipo') !== 'obra')
                    ->disabled(fn (?Compra $record): bool => self::compraBloqueada($record))
                    ->helperText('Bodega donde entra el stock al confirmar.'),

                Select::make('proyecto_id')
                    ->label('Obra destino')
                    // Solo obras VIVAS reciben material: a una terminada,
                    // cancelada o sin iniciar no se le imputa costo.
                    ->relationship('proyecto', 'nombre', fn ($query) => $query
                        ->whereIn('estado', [EstadoProyecto::EnEjecucion->value, EstadoProyecto::Pausada->value])
                        ->orderBy('nombre'))
                    ->searchable()
                    ->preload()
                    ->visible(fn (callable $get): bool => $get('destino_tipo') === 'obra')
                    ->required(fn (callable $get): bool => $get('destino_tipo') === 'obra')
                    ->disabled(fn (?Compra $record): bool => self::compraBloqueada($record))
                    ->helperText('El costo se imputa a esta obra al precio real de factura, sin pasar por bodega.'),

                Hidden::make('requisicion_id'),

                Placeholder::make('requisicion_enlazada')
                    ->label('Requisición enlazada')
                    ->content(function (callable $get): string {
                        $requisicion = Requisicion::query()->find($get('requisicion_id'));

                        return $requisicion !== null ? $requisicion->codigo : '—';
                    })
                    ->visible(fn (callable $get): bool => $get('requisicion_id') !== null)
                    ->helperText('Al confirmar, sus líneas quedarán despachadas a la obra (si la entrega es directa).'),

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

                TextInput::make('costo_envio')
                    ->label('Costo de envío (flete)')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->prefix('L.')
                    ->step('any')
                    ->live(debounce: 600)
                    ->helperText('Se reparte entre los materiales y forma parte de su costo (no es un gasto aparte).'),

                TextInput::make('descuento')
                    ->label('Descuento global')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->prefix('L.')
                    ->step('any')
                    ->live(debounce: 600)
                    ->helperText('Descuento de la factura completa; se reparte entre los materiales.'),

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
                    ->label('Líneas de la factura')
                    ->addActionLabel('+ Agregar material')
                    ->reorderable(false)
                    ->defaultItems(1)
                    ->minItems(1)
                    // Atajo "Registrar compra" desde una requisición
                    // (?requisicion=ID): las líneas nacen prellenadas con
                    // los materiales y cantidades FALTANTES — recepción
                    // solo captura el precio de factura.
                    ->default(fn (): array => self::lineasDesdeRequisicion())
                    ->columnSpanFull()
                    // Dividir una línea de factura entre destinos (150 en el
                    // papel → 50 a obra + 100 a bodega): duplicar y ajustar
                    // cantidad/destino. Los totales cuadran con la factura.
                    ->cloneable()
                    // Hoja de captura: una FILA por material, como se lee la
                    // factura. Las tarjetas apiladas eran inmanejables.
                    ->table([
                        TableColumn::make('Material'),
                        TableColumn::make('Cantidad')->width('110px'),
                        TableColumn::make('Precio factura')->width('150px'),
                        TableColumn::make('Costo neto')->width('140px'),
                        TableColumn::make('Subtotal neto')->width('130px'),
                        TableColumn::make('Enviar a')->width('180px'),
                        TableColumn::make('Exento')->width('80px'),
                    ])
                    ->compact()
                    ->disabled(function (?Compra $record): bool {
                        $estado = $record?->getAttribute('estado');

                        return $estado instanceof EstadoCompra && ! $estado->permiteEditar();
                    })
                    // El "Enviar a" viaja codificado y se decodifica al
                    // guardar (bodega_id XOR proyecto_id) — sin Hiddens.
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::decodificarDestino($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::decodificarDestino($data))
                    ->schema([
                        Select::make('material_id')
                            ->hiddenLabel()
                            ->relationship('material', 'nombre', fn ($query) => $query->where('activo', true)->orderBy('nombre'))
                            ->getOptionLabelFromRecordUsing(fn (Material $record): string => "{$record->codigo} — {$record->nombre}")
                            ->searchable(['codigo', 'nombre'])
                            ->preload()
                            ->required()
                            ->live()
                            // La marca fiscal del producto (exento) se hereda
                            // del catálogo — nadie la marca a mano por línea.
                            // Cambiarla recalcula el neto desde el precio de
                            // factura ya tecleado (si lo hay).
                            ->afterStateUpdated(function (mixed $state, Set $set, callable $get): void {
                                $material = is_numeric($state) ? Material::query()->find($state) : null;

                                $set('exento', $material !== null && $material->exento_isv);
                                self::aplicarPrecioFactura($set, $get);
                                self::avisarSiFueraDePresupuesto($get);
                            }),

                        TextInput::make('cantidad')
                            ->hiddenLabel()
                            ->numeric()
                            ->required()
                            ->minValue(0.0001)
                            ->step('any')
                            ->live(debounce: 500)
                            ->afterStateUpdated(fn (Set $set, callable $get) => self::refrescarSubtotal($set, $get)),

                        // Calculadora: se teclea el precio TAL CUAL lo dice la
                        // factura y el sistema deduce el neto — línea gravada
                        // divide entre 1.15; línea EXENTA va tal cual (su
                        // precio de factura no trae ISV).
                        TextInput::make('precio_con_isv')
                            ->hiddenLabel()
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.')
                            ->placeholder('Tal cual factura')
                            ->dehydrated(false)
                            ->live(debounce: 600)
                            ->afterStateUpdated(fn (Set $set, callable $get) => self::aplicarPrecioFactura($set, $get)),

                        TextInput::make('costo_unitario')
                            ->hiddenLabel()
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->prefix('L.')
                            ->step('any')
                            ->live(debounce: 500)
                            ->afterStateUpdated(fn (Set $set, callable $get) => self::refrescarSubtotal($set, $get)),

                        TextInput::make('subtotal_preview')
                            ->hiddenLabel()
                            ->prefix('L.')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (TextInput $component, callable $get): void {
                                $cantidad = $get('cantidad');
                                $costo = $get('costo_unitario');

                                if (is_numeric($cantidad) && is_numeric($costo)) {
                                    $component->state(number_format((float) $cantidad * (float) $costo, 2, '.', ''));
                                }
                            }),

                        // Compras mixtas: esta línea puede ir a un destino
                        // distinto al de la cabecera (100 bolsas a obra,
                        // 100 a bodega en la misma factura).
                        Select::make('destino_encoded')
                            ->hiddenLabel()
                            ->placeholder('Igual que la compra')
                            ->options(self::opcionesDestinoLinea())
                            ->live()
                            ->afterStateHydrated(function (Select $component, ?CompraLinea $record): void {
                                if ($record?->proyecto_id !== null) {
                                    $component->state("obra:{$record->proyecto_id}");
                                } elseif ($record?->bodega_id !== null) {
                                    $component->state("bodega:{$record->bodega_id}");
                                }
                            })
                            // Aviso INMEDIATO si el material no está en el
                            // presupuesto de la obra elegida (validación
                            // inline; el candado duro vive en Registrar).
                            ->afterStateUpdated(fn (callable $get) => self::avisarSiFueraDePresupuesto($get)),

                        Toggle::make('exento')
                            ->hiddenLabel()
                            ->default(false)
                            ->live()
                            // Marcar/desmarcar exento recalcula el neto desde
                            // el precio de factura (exento = va tal cual).
                            ->afterStateUpdated(fn (Set $set, callable $get) => self::aplicarPrecioFactura($set, $get)),
                    ]),

                Placeholder::make('totales_estimados')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(fn (callable $get): HtmlString => self::resumenEstimado($get)),
            ]);
    }

    /**
     * Validación INLINE del presupuesto: al combinar material + obra
     * destino, si la obra NO presupuesta ese material avisa al instante —
     * "se bloqueará al registrar" para quien no tiene el permiso de
     * imprevistos, "quedará fuera de presupuesto" para quien sí. El
     * candado duro vive en Registrar (ValidarDestinoObraCompraService).
     */
    private static function avisarSiFueraDePresupuesto(callable $get): void
    {
        $materialId = $get('material_id');

        if (! is_numeric($materialId)) {
            return;
        }

        // Obra destino: la propia de la línea, o la de la cabecera cuando
        // la línea hereda ("Igual que la compra") y la compra es directa.
        $destino = $get('destino_encoded');
        $obraId = null;

        if (is_string($destino) && str_starts_with($destino, 'obra:')) {
            $obraId = (int) substr($destino, 5);
        } elseif (blank($destino) && $get('../../destino_tipo') === 'obra' && is_numeric($get('../../proyecto_id'))) {
            $obraId = (int) $get('../../proyecto_id');
        }

        if ($obraId === null) {
            return;
        }

        $presupuesto = app(PresupuestoMaterialesProyectoService::class)
            ->paraMaterial($obraId, (int) $materialId);

        if ($presupuesto !== null && bccomp($presupuesto->presupuestado, '0', 4) > 0) {
            return; // Presupuestado: todo en orden, sin ruido.
        }

        $material = Material::query()->find((int) $materialId);
        $nombre = $material !== null ? $material->nombre : 'Este material';

        (auth()->user()?->can(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO) ?? false)
            ? Notification::make()
                ->title('Material fuera de presupuesto')
                ->body("{$nombre} no está en el presupuesto de esa obra. Puedes continuar (tienes el permiso de imprevistos) y quedará marcado como FUERA DE PRESUPUESTO en el control del proyecto.")
                ->warning()
                ->send()
            : Notification::make()
                ->title('Material fuera de presupuesto')
                ->body("{$nombre} no está en el presupuesto de esa obra — el registro se BLOQUEARÁ. Cambia el destino a bodega o pide la autorización a quien tenga el permiso \"Comprar fuera de presupuesto\".")
                ->danger()
                ->send();
    }

    /**
     * Deduce el costo NETO desde el precio de factura de la línea:
     *
     *  - línea GRAVADA (compra con ISV): neto = precio / 1.15;
     *  - línea EXENTA o compra sin ISV: el precio de factura no trae ISV,
     *    el neto es el precio tal cual.
     *
     * Se dispara al teclear el precio, al cambiar el material (hereda su
     * marca fiscal) y al alternar el toggle Exento.
     */
    private static function aplicarPrecioFactura(Set $set, callable $get): void
    {
        $precio = $get('precio_con_isv');

        if (! is_numeric($precio)) {
            self::refrescarSubtotal($set, $get);

            return;
        }

        $gravada = $get('exento') !== true && $get('../../aplica_isv') === true;
        $tasa = (float) config('honduras.impuestos.isv.tasa_general', 0.15);

        $neto = $gravada ? (float) $precio / (1 + $tasa) : (float) $precio;

        $set('costo_unitario', number_format($neto, 2, '.', ''));
        self::refrescarSubtotal($set, $get);
    }

    /**
     * Recalcula el subtotal de la fila en vivo (cantidad × costo neto).
     */
    private static function refrescarSubtotal(Set $set, callable $get): void
    {
        $cantidad = $get('cantidad');
        $costo = $get('costo_unitario');

        $set('subtotal_preview', is_numeric($cantidad) && is_numeric($costo)
            ? number_format((float) $cantidad * (float) $costo, 2, '.', '')
            : null);
    }

    /**
     * Resumen estimado al pie de la hoja (subtotal neto, ISV sobre las
     * líneas gravadas, total). Es un PREVIEW de captura: los totales
     * oficiales (con flete/descuento prorrateados) los fija el Service.
     *
     * Tarjetas con estilos INLINE y colores rgba translúcidos — el CSS del
     * panel no compila clases Tailwind arbitrarias en HtmlStrings (lección
     * de los paneles de Ejecución/Costos).
     */
    private static function resumenEstimado(callable $get): HtmlString
    {
        $lineas = $get('lineas');

        $subtotal = 0.0;
        $gravado = 0.0;

        foreach (is_array($lineas) ? $lineas : [] as $linea) {
            if (! is_array($linea) || ! is_numeric($linea['cantidad'] ?? null) || ! is_numeric($linea['costo_unitario'] ?? null)) {
                continue;
            }

            $monto = (float) $linea['cantidad'] * (float) $linea['costo_unitario'];
            $subtotal += $monto;

            if (($linea['exento'] ?? false) !== true) {
                $gravado += $monto;
            }
        }

        // Flete − descuento: se prorratean por valor de línea (igual que el
        // Service), así el ISV estimado grava la porción del ajuste que cae
        // en líneas gravadas.
        $flete = is_numeric($get('costo_envio')) ? (float) $get('costo_envio') : 0.0;
        $descuento = is_numeric($get('descuento')) ? (float) $get('descuento') : 0.0;
        $ajuste = $flete - $descuento;

        $gravadoEfectivo = $gravado + ($subtotal > 0 ? $ajuste * ($gravado / $subtotal) : 0.0);

        $tasa = (float) config('honduras.impuestos.isv.tasa_general', 0.15);
        $aplicaIsv = $get('aplica_isv') === true;
        $isv = $aplicaIsv ? round(max($gravadoEfectivo, 0) * $tasa, 2) : 0.0;
        $total = $subtotal + $ajuste + $isv;

        $fmt = fn (float $v): string => 'L. '.number_format($v, 2);
        $etiquetaIsv = $aplicaIsv ? 'ISV ('.rtrim(rtrim(number_format($tasa * 100, 2), '0'), '.').'%)' : 'ISV (exenta)';

        $tarjeta = function (string $label, string $valor, bool $destacada = false): string {
            $fondo = $destacada ? 'rgba(245,158,11,0.10)' : 'rgba(128,128,128,0.06)';
            $borde = $destacada ? 'rgba(245,158,11,0.35)' : 'rgba(128,128,128,0.20)';
            $tamano = $destacada ? '1.35rem' : '1.15rem';

            return '<div style="flex:1;min-width:160px;padding:.85rem 1.1rem;border-radius:.65rem;'
                ."background:{$fondo};border:1px solid {$borde};\">"
                .'<div style="font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;opacity:.65;">'
                .e($label).'</div>'
                ."<div style=\"font-size:{$tamano};font-weight:800;margin-top:.15rem;font-variant-numeric:tabular-nums;\">"
                .e($valor).'</div>'
                .'</div>';
        };

        $tarjetaAjuste = $ajuste !== 0.0
            ? $tarjeta('Flete − descuento', ($ajuste > 0 ? '+' : '−').' '.$fmt(abs($ajuste)))
            : '';

        return new HtmlString(
            '<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.25rem;">'
            .$tarjeta('Subtotal (neto)', $fmt($subtotal))
            .$tarjetaAjuste
            .$tarjeta($etiquetaIsv, $fmt($isv))
            .$tarjeta('Total estimado', $fmt($total), destacada: true)
            .'</div>'
            .'<div style="margin-top:.5rem;font-size:.72rem;opacity:.6;">'
            .'Debe coincidir con el total de la factura del proveedor — el flete y el descuento se prorratean al costo de cada material al guardar.'
            .'</div>'
        );
    }

    /**
     * Traduce el "Enviar a" codificado de la línea a sus columnas reales
     * (bodega_id XOR proyecto_id); vacío = hereda el destino de la compra.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function decodificarDestino(array $data): array
    {
        $encoded = $data['destino_encoded'] ?? null;
        unset($data['destino_encoded']);

        [$tipo, $id] = is_string($encoded) && str_contains($encoded, ':')
            ? explode(':', $encoded, 2)
            : [null, null];

        $data['bodega_id'] = $tipo === 'bodega' ? (int) $id : null;
        $data['proyecto_id'] = $tipo === 'obra' ? (int) $id : null;

        return $data;
    }

    /**
     * ¿La compra ya no admite cambios de cabecera? Editable mientras sea
     * Borrador (corregir proveedor/destino antes de confirmar es legítimo);
     * congelada después de confirmar (el stock y la CxP ya existen).
     */
    private static function compraBloqueada(?Compra $record): bool
    {
        $estado = $record?->getAttribute('estado');

        return $estado instanceof EstadoCompra && ! $estado->permiteEditar();
    }

    /**
     * Opciones del "Enviar a" por línea, agrupadas Bodegas / Obras.
     * Vacío (placeholder) = la línea hereda el destino de la cabecera.
     *
     * @return array<string, array<string, string>>
     */
    private static function opcionesDestinoLinea(): array
    {
        $bodegas = Bodega::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->mapWithKeys(fn (string $nombre, int $id): array => ["bodega:{$id}" => "Bodega: {$nombre}"])
            ->all();

        $obras = Proyecto::query()
            ->whereIn('estado', [EstadoProyecto::EnEjecucion->value, EstadoProyecto::Pausada->value])
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->mapWithKeys(fn (string $nombre, int $id): array => ["obra:{$id}" => "Obra: {$nombre}"])
            ->all();

        return array_filter([
            'Bodegas' => $bodegas,
            'Obras'   => $obras,
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
