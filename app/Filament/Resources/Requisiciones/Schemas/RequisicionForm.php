<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Schemas;

use App\Enums\EstadoRequisicion;
use App\Models\Material;
use App\Models\Requisicion;
use App\Models\User;
use App\Services\Requisiciones\PresupuestoMaterial;
use App\Services\Requisiciones\PresupuestoMaterialesProyectoService;
use App\Support\Roles;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class RequisicionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('requisicion_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Datos de la requisición')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->schema([
                            TextInput::make('codigo')
                                ->label('Código')
                                ->disabled()
                                ->dehydrated(false)
                                ->visible(fn (string $operation): bool => $operation === 'edit')
                                ->prefixIcon('heroicon-o-hashtag')
                                ->helperText('Se genera automáticamente: REQ-2026-00001, ...'),

                            Select::make('proyecto_id')
                                ->label('Obra (proyecto)')
                                ->relationship('proyecto', 'nombre', function ($query) {
                                    $query->orderBy('nombre');

                                    // El encargado solo pide para SUS obras.
                                    $user = auth()->user();

                                    if ($user instanceof User && Roles::soloEncargado($user)) {
                                        $query->whereHas('encargados', fn ($q) => $q->whereKey($user->id));
                                    }

                                    return $query;
                                })
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->disabledOn('edit')
                                // PEDIDO RÁPIDO: al elegir la obra se llena la tab de
                                // materiales con TODOS los presupuestados (cantidad
                                // vacía). El usuario solo escribe cantidades — las
                                // filas vacías no se guardan.
                                ->afterStateUpdated(function (Set $set, mixed $state, string $operation): void {
                                    if ($operation === 'create') {
                                        $set('lineas', is_numeric($state)
                                            ? self::lineasPrefill((int) $state)
                                            : []);
                                    }
                                })
                                ->helperText('La obra que solicita el material. No se cambia después de crear.'),

                            DatePicker::make('fecha_solicitud')
                                ->label('Fecha de solicitud')
                                ->default(now())
                                ->required()
                                // No editable: es la fecha real en que se pidió —
                                // cambiarla rompería la trazabilidad y el correlativo anual.
                                ->disabled()
                                ->dehydrated()
                                ->native(false)
                                ->helperText('Se registra automáticamente con la fecha de hoy.'),

                            DatePicker::make('fecha_necesaria')
                                ->label('Fecha necesaria en obra')
                                ->required()
                                ->native(false)
                                ->minDate(fn (callable $get) => $get('fecha_solicitud'))
                                ->helperText('Cuándo debe estar el material en obra sí o sí.'),

                            Textarea::make('notas')
                                ->label('Notas')
                                ->rows(2)
                                ->maxLength(500)
                                ->mayusculas()
                                ->placeholder('INSTRUCCIONES O CONTEXTO OPCIONAL')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Tab::make('Materiales solicitados')
                        ->icon('heroicon-o-cube')
                        ->schema([
                            Repeater::make('lineas')
                                ->relationship()
                                ->label('Materiales')
                                ->helperText('Escribe la cantidad solo en los materiales que necesitas. Las filas con cantidad vacía no se piden.')
                                ->addActionLabel('+ Agregar material')
                                ->reorderable(false)
                                ->defaultItems(0)
                                ->columnSpanFull()
                                // Hoja de pedido compacta: una fila por material.
                                // Crítico para tablet/teléfono — las tarjetas apiladas
                                // hacían la lista inmanejable con 10+ materiales.
                                ->table([
                                    TableColumn::make('Material'),
                                    TableColumn::make('Cantidad')->width('220px'),
                                ])
                                ->compact()
                                ->disabled(function (?Requisicion $record): bool {
                                    $estado = $record?->getAttribute('estado');

                                    return $estado instanceof EstadoRequisicion
                                        && ! $estado->permiteEditarLineas();
                                })
                                // Las filas del pedido rápido sin cantidad se descartan
                                // silenciosamente al guardar (null = no crear).
                                ->mutateRelationshipDataBeforeCreateUsing(
                                    fn (array $data): ?array => self::lineaConCantidad($data) ? $data : null
                                )
                                // Al menos un material con cantidad — valida el conjunto,
                                // no cada fila (las vacías son legítimas).
                                ->rule(static fn () => static function (string $attribute, mixed $value, Closure $fail): void {
                                    $conCantidad = collect(is_array($value) ? $value : [])
                                        ->filter(fn (mixed $linea): bool => is_array($linea) && self::lineaConCantidad($linea));

                                    if ($conCantidad->isEmpty()) {
                                        $fail('Indica la cantidad de al menos un material.');
                                    }
                                })
                                ->schema([
                                    Select::make('material_id')
                                        ->hiddenLabel()
                                        ->options(fn (callable $get): array => self::opcionesMaterial($get('../../proyecto_id')))
                                        // Líneas históricas con material que ya no está en las
                                        // opciones (inactivo o fuera de presupuesto) igual
                                        // muestran su nombre al editar.
                                        ->getOptionLabelUsing(function (mixed $value): ?string {
                                            $material = Material::query()->find($value);

                                            return $material !== null
                                                ? "{$material->codigo} — {$material->nombre}"
                                                : null;
                                        })
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->placeholder(fn (callable $get): string => is_numeric($get('../../proyecto_id'))
                                            ? 'Material presupuestado'
                                            : 'Primero seleccione la obra'),

                                    TextInput::make('cantidad_solicitada')
                                        ->hiddenLabel()
                                        ->numeric()
                                        // En crear puede quedar vacía (fila descartada);
                                        // en editar toda línea existente exige cantidad.
                                        ->required(fn (string $operation): bool => $operation !== 'create')
                                        ->placeholder('—')
                                        ->minValue(0.0001)
                                        ->step('any')
                                        ->live(debounce: 500)
                                        // La unidad SIEMPRE visible junto al número: pedir
                                        // "4" no significa nada; "4 BOLSA" sí.
                                        ->suffix(fn (callable $get): ?string => self::unidadDelMaterial(
                                            $get('../../proyecto_id'),
                                            $get('material_id'),
                                        ))
                                        // Solo advertencias (exceso / fuera de presupuesto):
                                        // el disponible ya se ve en el nombre del material,
                                        // y así la fila mantiene una sola línea de alto.
                                        ->helperText(fn (callable $get): string => self::estadoPresupuestario(
                                            $get('../../proyecto_id'),
                                            $get('material_id'),
                                            $get('cantidad_solicitada'),
                                        )),
                                ]),
                        ]),

                    Tab::make('Estado')
                        ->icon('heroicon-o-flag')
                        ->visible(fn (string $operation): bool => $operation === 'edit')
                        ->schema([
                            Section::make('Seguimiento')
                                ->icon('heroicon-o-information-circle')
                                ->schema([
                                    Placeholder::make('estado_actual')
                                        ->label('Estado actual')
                                        ->content(fn (?Requisicion $record): string => $record !== null
                                            ? $record->estado->getLabel()
                                            : '—'),
                                    Placeholder::make('transiciones_count')
                                        ->label('Transiciones registradas')
                                        ->content(fn (?Requisicion $record): string => $record !== null
                                            ? (string) $record->transiciones()->count()
                                            : '—'),
                                ])
                                ->columns(2),
                        ]),
                ]),
        ]);
    }

    // ─── Control presupuestario (materiales vs fichas del proyecto) ──

    /**
     * Filas del pedido rápido: una por cada material presupuestado de la
     * obra, con cantidad vacía. El usuario llena solo lo que necesita.
     *
     * @return array<int, array{material_id: int, cantidad_solicitada: null}>
     */
    private static function lineasPrefill(int $proyectoId): array
    {
        return self::presupuestoDelProyecto($proyectoId)
            // Solo materiales realmente presupuestados: los pedidos
            // históricos fuera de presupuesto no se ofrecen de nuevo.
            ->filter(fn (PresupuestoMaterial $pm): bool => bccomp($pm->presupuestado, '0', 4) > 0)
            ->sortBy('materialNombre')
            ->values()
            ->map(fn (PresupuestoMaterial $pm): array => [
                'material_id'         => $pm->materialId,
                'cantidad_solicitada' => null,
            ])
            ->all();
    }

    /**
     * ¿La línea tiene una cantidad válida (> 0)?
     *
     * @param array<string, mixed> $linea
     */
    private static function lineaConCantidad(array $linea): bool
    {
        $cantidad = $linea['cantidad_solicitada'] ?? null;

        return is_numeric($cantidad) && (float) $cantidad > 0;
    }

    /**
     * Opciones del select de material: SOLO los materiales presupuestados
     * en las fichas de la obra, con su disponible visible.
     *
     * Decisión de negocio (2026-07-05): no se puede requisar material que
     * las fichas no contemplan — si la obra necesita algo nuevo, primero
     * se corrige la composición (o se maneja como orden de cambio). Sin
     * obra elegida, el select queda vacío.
     *
     * @return array<int, string>
     */
    private static function opcionesMaterial(mixed $proyectoId): array
    {
        if (! is_numeric($proyectoId)) {
            return [];
        }

        $activos = Material::query()
            ->where('activo', true)
            ->pluck('id')
            ->flip();

        return self::presupuestoDelProyecto((int) $proyectoId)
            ->filter(fn (PresupuestoMaterial $pm): bool => $activos->has($pm->materialId)
                && bccomp($pm->presupuestado, '0', 4) > 0)
            ->sortBy('materialNombre')
            ->mapWithKeys(fn (PresupuestoMaterial $pm): array => [
                $pm->materialId => sprintf(
                    '%s — %s · Quedan: %s %s',
                    $pm->materialCodigo,
                    $pm->materialNombre,
                    self::num($pm->disponible()),
                    $pm->unidad,
                ),
            ])
            ->all();
    }

    /**
     * SOLO advertencias bajo el campo Cantidad (exceso o material fuera
     * de presupuesto). En el caso normal retorna '' para que la fila de
     * la hoja de pedido mantenga una sola línea de alto — el disponible
     * ya es visible en el nombre del material ("Quedan: X").
     *
     * NO bloquea: el sobreconsumo se permite y queda visible en el
     * control de materiales de la obra.
     */
    private static function estadoPresupuestario(mixed $proyectoId, mixed $materialId, mixed $cantidad): string
    {
        if (! is_numeric($proyectoId) || ! is_numeric($materialId)) {
            return '';
        }

        $pm = self::presupuestoDelProyecto((int) $proyectoId)->get((int) $materialId);

        if ($pm === null) {
            return '⚠️ Material fuera del presupuesto de esta obra.';
        }

        if (is_numeric($cantidad) && bccomp((string) $cantidad, $pm->disponible(), 4) > 0) {
            $exceso = bcsub((string) $cantidad, $pm->disponible(), 4);

            return '⚠️ Excede el presupuesto en '.self::num($exceso)." {$pm->unidad}.";
        }

        return '';
    }

    /**
     * Unidad de medida del material elegido, para el sufijo del campo
     * Cantidad (null mientras no haya material).
     */
    private static function unidadDelMaterial(mixed $proyectoId, mixed $materialId): ?string
    {
        if (! is_numeric($proyectoId) || ! is_numeric($materialId)) {
            return null;
        }

        $unidad = self::presupuestoDelProyecto((int) $proyectoId)
            ->get((int) $materialId)
            ?->unidad;

        return $unidad !== null && $unidad !== '' ? $unidad : null;
    }

    /**
     * Memoiza el presupuesto del proyecto durante el request: el repeater
     * llama estos helpers por cada línea y no queremos repetir las queries.
     * `once()` se limpia entre requests (Octane) y entre tests — un static
     * manual serviría datos stale en esos contextos.
     *
     * @return Collection<int, PresupuestoMaterial>
     */
    private static function presupuestoDelProyecto(int $proyectoId): Collection
    {
        return once(fn (): Collection => app(PresupuestoMaterialesProyectoService::class)
            ->porProyecto($proyectoId));
    }

    /**
     * Formatea un decimal para UI: sin ceros de cola (300.0000 → "300",
     * 12.5000 → "12.5").
     */
    private static function num(string $decimal): string
    {
        $limpio = rtrim(rtrim($decimal, '0'), '.');

        return $limpio === '' || $limpio === '-' ? '0' : $limpio;
    }
}
