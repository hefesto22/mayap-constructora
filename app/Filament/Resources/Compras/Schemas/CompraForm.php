<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Schemas;

use App\Enums\CategoriaCompra;
use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoProyecto;
use App\Enums\TipoDocumentoFiscal;
use App\Filament\Resources\Compras\Actions\AccionFotosFactura;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\MantenimientoMaquina;
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
                    self::tabLineasLibres(),
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

                // Categoría (decisión Mauricio 2026-07-20): materiales usa
                // el catálogo e inventario; taller/equipo/oficina son
                // compras LIBRES — líneas a mano, gasto directo.
                Select::make('categoria')
                    ->label('Categoría de la compra')
                    ->options(CategoriaCompra::options())
                    ->default(CategoriaCompra::Materiales->value)
                    ->required()
                    ->live()
                    ->native(false)
                    ->disabled(fn (?Compra $record): bool => self::compraBloqueada($record))
                    // Al pasar a compra libre, el destino vuelve a bodega
                    // (las libres no se entregan a obra).
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        if ($state !== CategoriaCompra::Materiales->value) {
                            $set('destino_tipo', 'bodega');
                            $set('proyecto_id', null);
                        }
                    })
                    ->helperText('Materiales usa el catálogo y mueve inventario. Taller, equipo y oficina se escriben a mano (sin catálogo) y no tocan inventario.'),

                // Vínculo opcional con la reparación de una máquina: el
                // gasto queda trazable y la fecha estimada del pedido
                // alimenta la del mantenimiento (bitácora incluida).
                Select::make('mantenimiento_id')
                    ->label('Mantenimiento de máquina (opcional)')
                    ->options(fn (): array => MantenimientoMaquina::query()
                        ->where('estado', EstadoMantenimiento::EnProceso)
                        ->with('maquina:id,nombre')
                        ->orderByDesc('id')
                        ->get()
                        ->mapWithKeys(fn (MantenimientoMaquina $m): array => [$m->id => "{$m->codigo} — {$m->maquina->nombre}"])
                        ->all())
                    ->searchable()
                    ->visible(fn (callable $get): bool => $get('categoria') === CategoriaCompra::Taller->value)
                    ->disabled(fn (?Compra $record): bool => self::compraBloqueada($record))
                    ->helperText('Solo reparaciones en proceso. Amarra estos repuestos a la máquina.'),

                // Pedidos con días de espera: ese día suena la campanita
                // "el pedido debería llegar". Compra del mismo día: vacío.
                DatePicker::make('fecha_estimada_llegada')
                    ->label('Fecha estimada de llegada (pedidos)')
                    ->native(false)
                    ->visible(fn (callable $get): bool => $get('categoria') !== CategoriaCompra::Materiales->value)
                    ->disabled(fn (?Compra $record): bool => self::compraBloqueada($record))
                    ->helperText('Solo pedidos con espera: al Registrar queda "por recibir" y ese día avisa. Si se compró y recogió el mismo día, dejala vacía y usá "Confirmar (recibida)".'),

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
                    // Las compras libres no eligen destino: no mueven
                    // inventario — la bodega queda solo como referencia.
                    ->visible(fn (callable $get): bool => $get('categoria') === CategoriaCompra::Materiales->value)
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
                    ->visible(fn (callable $get): bool => $get('destino_tipo') !== 'obra'
                        || $get('categoria') !== CategoriaCompra::Materiales->value)
                    ->required(fn (callable $get): bool => $get('destino_tipo') !== 'obra'
                        || $get('categoria') !== CategoriaCompra::Materiales->value)
                    ->disabled(fn (?Compra $record): bool => self::compraBloqueada($record))
                    ->helperText(fn (callable $get): string => $get('categoria') === CategoriaCompra::Materiales->value
                        ? 'Bodega donde entra el stock al confirmar.'
                        : 'Solo referencia administrativa: las compras libres no mueven inventario.'),

                Select::make('proyecto_id')
                    ->label('Obra destino')
                    // Solo obras VIVAS reciben material: a una terminada,
                    // cancelada o sin iniciar no se le imputa costo.
                    ->relationship('proyecto', 'nombre', fn ($query) => $query
                        ->whereIn('estado', [EstadoProyecto::EnEjecucion->value, EstadoProyecto::Pausada->value])
                        ->orderBy('nombre'))
                    ->searchable()
                    ->preload()
                    ->visible(fn (callable $get): bool => $get('destino_tipo') === 'obra'
                        && $get('categoria') === CategoriaCompra::Materiales->value)
                    ->required(fn (callable $get): bool => $get('destino_tipo') === 'obra'
                        && $get('categoria') === CategoriaCompra::Materiales->value)
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

                // Documento fiscal (decisión Mauricio 2026-07-19): opcional
                // en borrador (a veces llega después), pero SIN él la compra
                // no se confirma — y factura exige su número. La regla dura
                // vive en ConfirmarCompraService; aquí solo se guía.
                Select::make('tipo_documento_fiscal')
                    ->label('Documento fiscal emitido')
                    ->options(TipoDocumentoFiscal::options())
                    ->native(false)
                    ->live()
                    ->helperText('Qué emitió el proveedor. Se puede dejar vacío en borrador, pero es obligatorio para confirmar. El ISV va aparte: hay boletas sin ISV y facturas con él.'),

                TextInput::make('numero_factura')
                    ->label('N.º de factura')
                    ->maxLength(50)
                    ->placeholder('Factura del proveedor')
                    ->required(fn (callable $get): bool => $get('tipo_documento_fiscal') === TipoDocumentoFiscal::Factura->value)
                    ->helperText(fn (callable $get): ?string => $get('tipo_documento_fiscal') === TipoDocumentoFiscal::Factura->value
                        ? 'Obligatorio: el documento es factura.'
                        : null),

                // Fotos del documento (WebP automático). Después de
                // confirmar, se suben desde la acción "Fotos" de la tabla.
                AccionFotosFactura::campo(),

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
            ->visible(fn (callable $get): bool => $get('categoria') === CategoriaCompra::Materiales->value)
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
                        TableColumn::make('Cantidad')->width('160px'),
                        TableColumn::make('Precio factura')->width('130px'),
                        TableColumn::make('Total línea')->width('130px'),
                        TableColumn::make('Costo neto')->width('120px'),
                        TableColumn::make('Subtotal neto')->width('120px'),
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
                            // La unidad del catálogo al lado del número:
                            // responde "¿100 QUÉ estoy comprando?" (pedido
                            // Mauricio 2026-07-20 — el agua de pipa se
                            // compra por m³, no "por pipa").
                            ->suffix(fn (callable $get): ?string => self::unidadDeMaterial($get('material_id')))
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Set $set, callable $get): void {
                                self::refrescarSubtotal($set, $get);
                                self::sincronizarTotalLinea($set, $get);
                            }),

                        // Calculadora: se teclea el precio TAL CUAL lo dice la
                        // factura y el sistema deduce el neto — línea gravada
                        // divide entre 1.15; línea EXENTA va tal cual (su
                        // precio de factura no trae ISV).
                        // SE GUARDA (decisión Mauricio 2026-07-20): este
                        // precio es la fuente de la verdad del total — el
                        // Service reconstruye la factura desde aquí para
                        // que cuadre AL CENTAVO.
                        TextInput::make('precio_factura')
                            ->hiddenLabel()
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.')
                            ->placeholder('Precio unitario')
                            ->live(debounce: 600)
                            ->afterStateUpdated(function (Set $set, callable $get): void {
                                self::aplicarPrecioFactura($set, $get);
                                self::sincronizarTotalLinea($set, $get);
                            }),

                        // El ATAJO del capturista (pedido Mauricio
                        // 2026-07-20): la factura casi siempre trae el
                        // TOTAL del renglón — se teclea aquí y el precio
                        // unitario se deduce solo (10,000 entre 1,000
                        // bloques = L 10 cada uno). Ligado en vivo con
                        // cantidad y precio; no se guarda: es captura.
                        TextInput::make('total_linea')
                            ->hiddenLabel()
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.')
                            ->placeholder('o total del renglón')
                            ->dehydrated(false)
                            ->live(debounce: 600)
                            ->afterStateHydrated(function (TextInput $component, callable $get): void {
                                $cantidad = $get('cantidad');
                                $precio = $get('precio_factura');

                                if (is_numeric($cantidad) && is_numeric($precio)) {
                                    $component->state(number_format((float) $cantidad * (float) $precio, 2, '.', ''));
                                }
                            })
                            ->afterStateUpdated(fn (Set $set, callable $get) => self::aplicarTotalLinea($set, $get)),

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
     * Líneas LIBRES (decisión Mauricio 2026-07-20): repuestos de taller,
     * equipo y oficina no tienen catálogo — cada línea se escribe a mano
     * (descripción + cantidad + precio) y es GASTO DIRECTO: nunca genera
     * movimientos de inventario. Mismo binding a `lineas`; solo uno de
     * los dos repeaters está visible (y dehidrata) según la categoría.
     */
    private static function tabLineasLibres(): Tab
    {
        return Tab::make('Detalle de la compra')
            ->icon('heroicon-o-clipboard-document-list')
            ->visible(fn (callable $get): bool => $get('categoria') !== CategoriaCompra::Materiales->value)
            ->schema([
                Repeater::make('lineas_libres')
                    ->relationship('lineas')
                    ->label('Líneas de la factura')
                    ->addActionLabel('+ Agregar línea')
                    ->reorderable(false)
                    ->defaultItems(1)
                    ->minItems(1)
                    ->columnSpanFull()
                    ->cloneable()
                    ->table([
                        TableColumn::make('Descripción (a mano)'),
                        TableColumn::make('Cantidad')->width('110px'),
                        TableColumn::make('Precio factura')->width('130px'),
                        TableColumn::make('Total línea')->width('130px'),
                        TableColumn::make('Costo neto')->width('120px'),
                        TableColumn::make('Subtotal neto')->width('120px'),
                        TableColumn::make('Exento')->width('80px'),
                    ])
                    ->compact()
                    ->disabled(function (?Compra $record): bool {
                        $estado = $record?->getAttribute('estado');

                        return $estado instanceof EstadoCompra && ! $estado->permiteEditar();
                    })
                    // Sin catálogo ni destino: material y bodega/obra van
                    // en NULL — la línea es gasto, no inventario.
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::normalizarLineaLibre($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizarLineaLibre($data))
                    ->schema([
                        TextInput::make('descripcion')
                            ->hiddenLabel()
                            ->required()
                            ->maxLength(255)
                            ->mayusculas()
                            ->placeholder('FILTRO DE ACEITE 1R-0750'),

                        TextInput::make('cantidad')
                            ->hiddenLabel()
                            ->numeric()
                            ->required()
                            ->minValue(0.0001)
                            ->step('any')
                            ->default(1)
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Set $set, callable $get): void {
                                self::refrescarSubtotal($set, $get);
                                self::sincronizarTotalLinea($set, $get);
                            }),

                        // SE GUARDA (decisión Mauricio 2026-07-20): este
                        // precio es la fuente de la verdad del total — el
                        // Service reconstruye la factura desde aquí para
                        // que cuadre AL CENTAVO.
                        TextInput::make('precio_factura')
                            ->hiddenLabel()
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.')
                            ->placeholder('Precio unitario')
                            ->live(debounce: 600)
                            ->afterStateUpdated(function (Set $set, callable $get): void {
                                self::aplicarPrecioFactura($set, $get);
                                self::sincronizarTotalLinea($set, $get);
                            }),

                        // El ATAJO del capturista (pedido Mauricio
                        // 2026-07-20): la factura casi siempre trae el
                        // TOTAL del renglón — se teclea aquí y el precio
                        // unitario se deduce solo (10,000 entre 1,000
                        // bloques = L 10 cada uno). Ligado en vivo con
                        // cantidad y precio; no se guarda: es captura.
                        TextInput::make('total_linea')
                            ->hiddenLabel()
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.')
                            ->placeholder('o total del renglón')
                            ->dehydrated(false)
                            ->live(debounce: 600)
                            ->afterStateHydrated(function (TextInput $component, callable $get): void {
                                $cantidad = $get('cantidad');
                                $precio = $get('precio_factura');

                                if (is_numeric($cantidad) && is_numeric($precio)) {
                                    $component->state(number_format((float) $cantidad * (float) $precio, 2, '.', ''));
                                }
                            })
                            ->afterStateUpdated(fn (Set $set, callable $get) => self::aplicarTotalLinea($set, $get)),

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

                        Toggle::make('exento')
                            ->hiddenLabel()
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(fn (Set $set, callable $get) => self::aplicarPrecioFactura($set, $get)),
                    ]),

                Placeholder::make('totales_estimados_libres')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(fn (callable $get): HtmlString => self::resumenEstimado($get, 'lineas_libres')),
            ]);
    }

    /**
     * La línea libre nunca lleva catálogo ni destino propio.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function normalizarLineaLibre(array $data): array
    {
        $data['material_id'] = null;
        $data['bodega_id'] = null;
        $data['proyecto_id'] = null;

        return $data;
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
     * Símbolo de la unidad de medida del material ('M3', 'BOLSA', 'GAL'):
     * se pinta como sufijo de la cantidad para que quien captura sepa en
     * QUÉ unidad está comprando. Cache por request: los repeaters
     * re-rinden seguido y no vale una consulta por tecla.
     */
    private static function unidadDeMaterial(mixed $materialId): ?string
    {
        static $cache = [];

        if (! is_numeric($materialId)) {
            return null;
        }

        $id = (int) $materialId;

        if (! array_key_exists($id, $cache)) {
            $unidad = Material::query()->with('unidadMedida')->find($id)?->unidadMedida;

            // El sufijo debe ser CORTO: el símbolo ('M3', 'QQ'), y si la
            // unidad no lo tiene, SOLO la primera palabra del nombre
            // ('DÍA (ALQUILER)' → 'DÍA') — un sufijo largo aplastaba el
            // campo y cantidades grandes como 100,000 no se veían.
            $cache[$id] = match (true) {
                $unidad === null          => null,
                $unidad->simbolo !== null => $unidad->simbolo,
                default                   => mb_strimwidth(explode(' ', trim($unidad->nombre))[0], 0, 8, ''),
            };
        }

        return $cache[$id];
    }

    /**
     * El total del renglón manda: deduce el precio unitario de factura
     * (total / cantidad, 4 decimales) y de ahí el neto — el camino
     * inverso de siempre, para copiar la factura tal cual.
     */
    private static function aplicarTotalLinea(Set $set, callable $get): void
    {
        $total = $get('total_linea');
        $cantidad = $get('cantidad');

        if (! is_numeric($total) || ! is_numeric($cantidad) || (float) $cantidad <= 0) {
            return;
        }

        $set('precio_factura', number_format((float) $total / (float) $cantidad, 4, '.', ''));
        self::aplicarPrecioFactura($set, $get);
    }

    /**
     * Mantiene el total del renglón al día cuando cambian cantidad o
     * precio unitario (cantidad × precio, 2 decimales).
     */
    private static function sincronizarTotalLinea(Set $set, callable $get): void
    {
        $cantidad = $get('cantidad');
        $precio = $get('precio_factura');

        $set('total_linea', is_numeric($cantidad) && is_numeric($precio)
            ? number_format((float) $cantidad * (float) $precio, 2, '.', '')
            : null);
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
        $precio = $get('precio_factura');

        if (! is_numeric($precio)) {
            self::refrescarSubtotal($set, $get);

            return;
        }

        $gravada = $get('exento') !== true && $get('../../aplica_isv') === true;
        $tasa = (float) config('honduras.impuestos.isv.tasa_general', 0.15);

        $neto = $gravada ? (float) $precio / (1 + $tasa) : (float) $precio;

        // 4 decimales y NO 2: el neto es un derivado (precio / 1.15) y
        // redondearlo de más arrastraba centavos al multiplicar por la
        // cantidad (100 × L 10.00 daban L 1,000.50 — caso 2026-07-20).
        $set('costo_unitario', number_format($neto, 4, '.', ''));
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
    private static function resumenEstimado(callable $get, string $campo = 'lineas'): HtmlString
    {
        $lineas = $get($campo);

        $tasa = (float) config('honduras.impuestos.isv.tasa_general', 0.15);
        $aplicaIsvLineas = $get('aplica_isv') === true;

        $subtotal = 0.0;
        $gravado = 0.0;
        $isvLineas = 0.0;

        foreach (is_array($lineas) ? $lineas : [] as $linea) {
            if (! is_array($linea) || ! is_numeric($linea['cantidad'] ?? null) || ! is_numeric($linea['costo_unitario'] ?? null)) {
                continue;
            }

            $gravada = $aplicaIsvLineas && ($linea['exento'] ?? false) !== true;

            // Con precio de factura, la línea se estima COMO EL SERVICE:
            // bruto exacto de factura → neto e ISV por diferencia (así el
            // total del preview cuadra al centavo con el papel).
            if ($gravada && is_numeric($linea['precio_factura'] ?? null)) {
                $bruto = round((float) $linea['cantidad'] * (float) $linea['precio_factura'], 2);
                $monto = round($bruto / (1 + $tasa), 2);
                $isvLineas += $bruto - $monto;
            } else {
                $monto = round((float) $linea['cantidad'] * (float) $linea['costo_unitario'], 2);

                if ($gravada) {
                    $isvLineas += round($monto * (1 + $tasa), 2) - $monto;
                }
            }

            $subtotal += $monto;

            if ($gravada) {
                $gravado += $monto;
            }
        }

        // Flete − descuento: se prorratean por valor de línea (igual que el
        // Service), así el ISV estimado grava la porción del ajuste que cae
        // en líneas gravadas.
        $flete = is_numeric($get('costo_envio')) ? (float) $get('costo_envio') : 0.0;
        $descuento = is_numeric($get('descuento')) ? (float) $get('descuento') : 0.0;
        $ajuste = $flete - $descuento;

        // El ajuste (flete − descuento) prorrateado a gravadas también
        // paga ISV — misma regla del Service.
        $ajusteGravado = $subtotal > 0 ? $ajuste * ($gravado / $subtotal) : 0.0;

        $aplicaIsv = $get('aplica_isv') === true;
        $isv = $aplicaIsv
            ? round($isvLineas + (round($ajusteGravado * (1 + $tasa), 2) - round($ajusteGravado, 2)), 2)
            : 0.0;
        $total = round($subtotal + $ajuste, 2) + $isv;

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
