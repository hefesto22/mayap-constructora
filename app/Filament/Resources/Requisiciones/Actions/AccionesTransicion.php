<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Actions;

use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Compras\CompraResource;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\User;
use App\Services\Inventario\Ubicacion;
use App\Services\Requisiciones\TransicionarRequisicionService;
use App\Support\Cantidad;
use App\Support\Permisos;
use App\Support\Roles;
use BezhanSalleh\FilamentShield\Support\Utils;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Acciones de transición de una requisición — cada una llama al
 * TransicionarRequisicionService (única puerta de la máquina de estados) y
 * solo es visible cuando el estado actual permite ese avance. El usuario
 * autenticado queda registrado como responsable en la bitácora.
 *
 * Las acciones que requieren cantidades por línea (autorizar, recibir) usan
 * un repeater pre-llenado desde la requisición; el resto son confirmaciones
 * o un formulario corto.
 */
final class AccionesTransicion
{
    /**
     * Solicitada → Autorizada. Permite ajustar la cantidad autorizada por
     * línea (igual o menor a la solicitada).
     */
    public static function autorizar(): Action
    {
        return Action::make('autorizar')
            ->label('Autorizar')
            ->icon('heroicon-o-check-circle')
            ->color('info')
            // Vencida NO se autoriza: primero Reprogramar (con motivo) o
            // Rechazar. El Service repite el guard — esto es solo la UI.
            ->visible(fn (Requisicion $record): bool => $record->estado === EstadoRequisicion::Solicitada
                && ! $record->fechaNecesariaVencida()
                && self::puede(Permisos::AUTORIZAR_REQUISICION))
            ->modalHeading('Autorizar requisición')
            ->modalSubmitActionLabel('Autorizar')
            ->fillForm(self::prellenarLineas('cantidad_solicitada', conStock: true))
            ->schema([self::repeaterLineas('Autorizar', conStock: true)])
            ->action(function (Requisicion $record, array $data): void {
                app(TransicionarRequisicionService::class)->autorizar(
                    $record,
                    self::cantidadesPorLinea($data),
                    self::userId(),
                );

                Notification::make()->title('Requisición autorizada')->success()->send();
            });
    }

    /**
     * Solicitada con fecha necesaria VENCIDA → misma Solicitada con nueva
     * fecha. No es una transición de estado: actualiza la fecha y deja el
     * renglón Solicitada → Solicitada en la bitácora con el motivo. Sin
     * esto, la requisición vencida queda sin salida (Autorizar se oculta).
     */
    public static function reprogramar(): Action
    {
        return Action::make('reprogramar')
            ->label('Reprogramar')
            ->icon('heroicon-o-calendar-days')
            ->color('warning')
            ->visible(fn (Requisicion $record): bool => $record->estado === EstadoRequisicion::Solicitada
                && $record->fechaNecesariaVencida()
                && self::puedeReprogramar($record))
            ->modalHeading('Reprogramar fecha necesaria')
            ->modalDescription(fn (Requisicion $record): string => 'La fecha necesaria ('
                .$record->fecha_necesaria->format('d/m/Y')
                .') ya venció sin atenderse. Para poder autorizarla, indicá la nueva fecha y el motivo — queda en la bitácora.')
            ->modalSubmitActionLabel('Reprogramar')
            ->schema([
                DatePicker::make('fecha_necesaria')
                    ->label('Nueva fecha necesaria')
                    ->required()
                    ->native(false)
                    ->minDate(today()),
                Textarea::make('motivo')
                    ->label('Motivo de la reprogramación')
                    ->required()
                    ->rows(3)
                    ->placeholder('¿Por qué no se atendió a tiempo y sigue haciendo falta?'),
            ])
            ->action(function (Requisicion $record, array $data): void {
                app(TransicionarRequisicionService::class)->reprogramar(
                    $record,
                    Carbon::parse((string) $data['fecha_necesaria']),
                    (string) $data['motivo'],
                    self::userId(),
                );

                Notification::make()->title('Fecha necesaria reprogramada')->success()->send();
            });
    }

    /**
     * Autorizada / RequisicionCompra → Despachada. Elige la bodega de la que
     * sale el material; descuenta stock real con WAC. Si no hay stock, el
     * Service la manda a Requisición de compra.
     */
    public static function despachar(): Action
    {
        return Action::make('despachar')
            ->label('Despachar')
            ->icon('heroicon-o-truck')
            ->color('warning')
            ->visible(fn (Requisicion $record): bool => in_array(
                $record->estado,
                [EstadoRequisicion::Autorizada, EstadoRequisicion::RequisicionCompra],
                strict: true,
            ) && self::puede(Permisos::DESPACHAR_REQUISICION))
            ->modalHeading('Despachar a obra')
            ->modalSubmitActionLabel('Despachar')
            ->schema([
                Select::make('bodega_id')
                    ->label('Bodega de salida')
                    ->options(function (): array {
                        $query = Bodega::query()->where('activo', true)->orderBy('nombre');

                        // El usuario solo despacha desde SUS bodegas (Fase 2).
                        $user = auth()->user();

                        if ($user instanceof User) {
                            $query->visibleParaUsuario($user);
                        }

                        return $query->pluck('nombre', 'id')->all();
                    })
                    ->required()
                    ->native(false),
                Textarea::make('nota')->label('Nota (opcional)')->rows(2),
            ])
            ->action(function (Requisicion $record, array $data): void {
                app(TransicionarRequisicionService::class)->despachar(
                    $record,
                    Ubicacion::bodega((int) $data['bodega_id']),
                    self::userId(),
                    is_string($data['nota'] ?? null) ? $data['nota'] : null,
                );

                if ($record->fresh()?->estado === EstadoRequisicion::RequisicionCompra) {
                    Notification::make()
                        ->title('Sin stock suficiente')
                        ->body('La requisición pasó a Requisición de compra. Registrá la entrada y volvé a despachar.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()->title('Material despachado a obra')->success()->send();
            });
    }

    /**
     * Despachada → EnTransito. SOLO en la vía bodega.
     *
     * En una compra directa a obra el material nunca salió de una bodega
     * nuestra: no hay tramo que marcar. El botón se esconde y el Service
     * repite el guard — bug de REQ-2026-00005: el botón se mostraba igual
     * y alguien lo apretó un minuto después del despacho directo.
     */
    public static function marcarEnTransito(): Action
    {
        return Action::make('marcar_en_transito')
            ->label('Marcar en tránsito')
            ->icon('heroicon-o-arrow-right-circle')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (Requisicion $record): bool => $record->estado === EstadoRequisicion::Despachada
                && ! $record->esDespachoDirecto()
                && self::puede(Permisos::DESPACHAR_REQUISICION))
            ->action(function (Requisicion $record): void {
                app(TransicionarRequisicionService::class)->marcarEnTransito($record, self::userId());

                Notification::make()->title('Requisición en tránsito')->success()->send();
            });
    }

    /**
     * → Recibida. Captura cuánto llegó realmente por línea.
     *
     * Dos puertas de entrada, distintas de verdad y no solo de nombre:
     *
     *  - VÍA BODEGA (desde EnTransito) → "Recibir": se cuenta contra lo que
     *    despachó la bodega; un faltante es de bodega o del transporte.
     *  - COMPRA DIRECTA (desde Despachada) → "Confirmar recepción": se
     *    cuenta contra lo que trajo el proveedor; un faltante es un reclamo
     *    al proveedor que pega en la cuenta por pagar.
     *
     * En compra directa este botón solo queda pendiente cuando la recepción
     * de la compra la verificó la OFICINA: si la verificó el encargado de
     * la obra, la requisición ya se recibió y concilió sola — no lo hacemos
     * contar dos veces el mismo material el mismo día.
     *
     * La visibilidad la decide la máquina de estados (que ya conoce el
     * origen), no una lista de estados escrita a mano acá.
     */
    public static function recibir(): Action
    {
        return Action::make('recibir')
            ->label(fn (Requisicion $record): string => $record->esDespachoDirecto()
                ? 'Confirmar recepción'
                : 'Recibir')
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('primary')
            ->visible(fn (Requisicion $record): bool => $record->puedeTransicionarA(EstadoRequisicion::Recibida)
                && self::puedeRecibir($record))
            ->modalHeading(fn (Requisicion $record): string => $record->esDespachoDirecto()
                ? 'Confirmar lo que entregó el proveedor'
                : 'Confirmar recepción en obra')
            ->modalDescription(fn (Requisicion $record): ?string => $record->esDespachoDirecto()
                ? 'Contá lo que el proveedor dejó en la obra. Si falta algo, queda como discrepancia y compras le reclama.'
                : null)
            ->modalSubmitActionLabel('Confirmar recepción')
            ->fillForm(self::prellenarLineas('cantidad_despachada'))
            ->schema([self::repeaterLineas('Recibido')])
            ->action(function (Requisicion $record, array $data): void {
                app(TransicionarRequisicionService::class)->recibir(
                    $record,
                    self::cantidadesPorLinea($data),
                    self::userId(),
                );

                Notification::make()->title('Recepción registrada')->success()->send();
            });
    }

    /**
     * Recibida → Cerrada o Discrepancia (según cuadre despachado vs recibido).
     */
    public static function conciliar(): Action
    {
        return Action::make('conciliar')
            ->label('Conciliar y cerrar')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Compara lo despachado con lo recibido. Si cuadra, cierra la requisición; si no, la marca en discrepancia.')
            ->visible(fn (Requisicion $record): bool => $record->estado === EstadoRequisicion::Recibida
                && self::puede(Permisos::DESPACHAR_REQUISICION))
            ->action(function (Requisicion $record): void {
                $resultado = app(TransicionarRequisicionService::class)->conciliar($record, self::userId());

                if ($resultado === EstadoRequisicion::Discrepancia) {
                    Notification::make()
                        ->title('Discrepancia detectada')
                        ->body('Lo recibido no coincide con lo despachado. Revisá las líneas.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title('Requisición cerrada')->success()->send();
            });
    }

    /**
     * Atajo desde "Requisición de compra": abre el formulario de Compra
     * prellenado con los materiales y cantidades faltantes de esta
     * requisición, ya enlazada. Evita capturar la compra desde cero.
     */
    public static function registrarEntrada(): Action
    {
        return Action::make('registrar_entrada')
            ->label('Registrar compra')
            ->icon('heroicon-o-shopping-cart')
            ->color('success')
            ->visible(fn (Requisicion $record): bool => $record->estado === EstadoRequisicion::RequisicionCompra
                && self::puede(Permisos::REALIZAR_COMPRA_REQUISICION))
            ->url(fn (Requisicion $record): string => CompraResource::getUrl(
                'create',
                ['requisicion' => $record->id],
            ));
    }

    /**
     * Puente hacia la verificación de la compra (2026-08-07).
     *
     * Mientras la requisición espera una compra, lo que la obra tiene que
     * hacer NO es "recibir la requisición" sino CONTAR lo que trajo el
     * proveedor contra la factura — y esa acción vive en Compras. Sin este
     * botón el encargado veía "llega hoy" en su listado y se quedaba
     * esperando un botón que solo aparece DESPUÉS de esa verificación.
     *
     * No duplica el modal de verificación (única fuente: ComprasTable):
     * solo abre el camino hacia él.
     */
    public static function verificarLlegada(): Action
    {
        return Action::make('verificar_llegada')
            ->label('Verificar lo que llegó')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('warning')
            // Solo desde el día prometido: antes no hay nada que contar y
            // el botón llevaría a una acción deshabilitada. La columna
            // "Llega" ya le dice al encargado qué día esperar.
            ->visible(fn (Requisicion $record): bool => $record->esperandoLlegada()
                && $record->fecha_estimada_llegada?->gt(today()) !== true
                && self::puede(Permisos::VERIFICAR_RECEPCION_COMPRA)
                && self::alcanzaLaObra($record))
            ->url(fn (Requisicion $record): string => CompraResource::getUrl(
                'index',
                ['tableSearch' => $record->compraEnCamino()->codigo ?? ''],
            ));
    }

    /**
     * Rechaza la requisición desde un estado temprano.
     */
    public static function rechazar(): Action
    {
        return Action::make('rechazar')
            ->label('Rechazar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Requisicion $record): bool => in_array(
                $record->estado,
                [EstadoRequisicion::Solicitada, EstadoRequisicion::Autorizada, EstadoRequisicion::RequisicionCompra],
                strict: true,
            ) && self::puede(Permisos::RECHAZAR_REQUISICION))
            ->modalHeading('Rechazar requisición')
            ->modalSubmitActionLabel('Rechazar')
            ->schema([
                Textarea::make('nota')->label('Motivo del rechazo')->required()->rows(3),
            ])
            ->action(function (Requisicion $record, array $data): void {
                app(TransicionarRequisicionService::class)->rechazar(
                    $record,
                    self::userId(),
                    is_string($data['nota'] ?? null) ? $data['nota'] : null,
                );

                Notification::make()->title('Requisición rechazada')->success()->send();
            });
    }

    /**
     * Pre-llena el repeater de líneas desde la requisición, usando el campo
     * dado como cantidad por defecto (solicitada para autorizar, despachada
     * para recibir). Con $conStock, agrega el stock total en bodegas de cada
     * material para que quien autoriza decida con información real.
     *
     * @return Closure(Requisicion): array<string, mixed>
     */
    private static function prellenarLineas(string $campoDefault, bool $conStock = false): Closure
    {
        return function (Requisicion $record) use ($campoDefault, $conStock): array {
            $lineas = $record->lineas()->with('material:id,codigo,nombre,consumo_inmediato')->get();

            $stock = $conStock
                ? Existencia::query()
                    ->whereNotNull('bodega_id')
                    ->whereIn('material_id', $lineas->pluck('material_id'))
                    ->groupBy('material_id')
                    ->selectRaw('material_id, SUM(cantidad) AS total')
                    ->pluck('total', 'material_id')
                : collect();

            return [
                'lineas' => $lineas
                    ->map(function (RequisicionLinea $linea) use ($campoDefault, $conStock, $stock): array {
                        $fila = [
                            'linea_id' => $linea->id,
                            'material' => $linea->material->codigo.' — '.$linea->material->nombre,
                            // Solo lectura → 2 decimales; editable → sin
                            // ceros de cola (la BD guarda escala 4 intacta).
                            'cantidad_solicitada' => Cantidad::corta($linea->cantidad_solicitada),
                            'cantidad'            => Cantidad::sinCeros((string) $linea->getAttribute($campoDefault)),
                        ];

                        if ($conStock) {
                            // Consumibles no almacenables (agua de pipa): nunca
                            // hay stock — no es un faltante, es su flujo normal.
                            if ($linea->material->consumo_inmediato) {
                                $fila['stock'] = 'Compra directa (no almacenable)';
                            } else {
                                $disponible = (string) ($stock->get($linea->material_id) ?? '0');
                                $alcanza = bccomp($disponible, (string) $linea->cantidad_solicitada, 4) >= 0;

                                $fila['stock'] = Cantidad::sinCeros($disponible)
                                    .($alcanza ? ' ✓' : ' ✗ INSUFICIENTE');
                            }
                        }

                        return $fila;
                    })
                    ->all(),
            ];
        };
    }

    /**
     * Repeater de líneas de solo-lectura salvo la columna de cantidad.
     */
    private static function repeaterLineas(string $labelCantidad, bool $conStock = false): Repeater
    {
        return Repeater::make('lineas')
            ->label('Líneas')
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->columnSpanFull()
            ->schema(array_filter([
                Hidden::make('linea_id'),
                TextInput::make('material')->label('Material')->disabled()->columnSpan(2),
                TextInput::make('cantidad_solicitada')->label('Solicitado')->disabled(),
                $conStock
                    ? TextInput::make('stock')
                        ->label('Stock en bodegas')
                        ->disabled()
                        ->helperText('✗ = habrá que comprar antes de despachar.')
                    : null,
                TextInput::make('cantidad')
                    ->label($labelCantidad)
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step('any'),
            ]))
            ->columns($conStock ? 5 : 4);
    }

    /**
     * Traduce los datos del repeater a [linea_id => cantidad] para el Service.
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, string>
     */
    private static function cantidadesPorLinea(array $data): array
    {
        /** @var array<int, array{linea_id: int|string, cantidad: int|string}> $lineas */
        $lineas = $data['lineas'] ?? [];

        $cantidades = [];

        foreach ($lineas as $linea) {
            $cantidades[(int) $linea['linea_id']] = (string) $linea['cantidad'];
        }

        return $cantidades;
    }

    /**
     * Id del usuario autenticado normalizado a ?int (el contrato de Auth
     * devuelve int|string|null; nuestros usuarios usan id entero).
     */
    /**
     * ¿El usuario tiene este permiso del flujo? (pestaña Personalizados
     * de Roles — todo administrable desde el panel, nunca por rol fijo).
     */
    private static function puede(string $permiso): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can($permiso);
    }

    /**
     * Reprograma: quien puede autorizar (decide si el pedido sigue vivo) y
     * también el solicitante o el encargado de ESA obra — es su pedido y
     * son quienes saben si el material todavía hace falta. Todo queda
     * igual de trazado en la bitácora.
     */
    private static function puedeReprogramar(Requisicion $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->can(Permisos::AUTORIZAR_REQUISICION)) {
            return true;
        }

        return $record->solicitante_id === $user->id
            || $record->proyecto->esEncargado($user);
    }

    /**
     * Recibe en obra: permiso "Recibir material en obra" + ALCANCE — solo
     * el encargado de ESA obra (quien está físicamente ahí). Gerencia y
     * admin son el respaldo universal.
     */
    private static function puedeRecibir(Requisicion $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can(Permisos::RECIBIR_REQUISICION)) {
            return false;
        }

        if ($user->hasAnyRole([Roles::GERENCIA, Utils::getSuperAdminName()])) {
            return true;
        }

        return $record->proyecto->esEncargado($user);
    }

    /**
     * Verificar lo que llegó a la obra: el encargado de ESA obra, que es
     * quien está físicamente ahí para contar los bultos. Gerencia y admin
     * son el respaldo universal — mismo alcance que la verificación de la
     * compra (AlcanceDestinoCompra), para no inventar una regla paralela.
     */
    private static function alcanzaLaObra(Requisicion $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->hasAnyRole([Roles::GERENCIA, Utils::getSuperAdminName()])
            || $record->proyecto->esEncargado($user);
    }

    private static function userId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}
