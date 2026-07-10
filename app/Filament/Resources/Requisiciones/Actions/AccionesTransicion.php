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
use App\Support\Permisos;
use App\Support\Roles;
use BezhanSalleh\FilamentShield\Support\Utils;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

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
            ->visible(fn (Requisicion $record): bool => $record->estado === EstadoRequisicion::Solicitada
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
     * Despachada → EnTransito.
     */
    public static function marcarEnTransito(): Action
    {
        return Action::make('marcar_en_transito')
            ->label('Marcar en tránsito')
            ->icon('heroicon-o-arrow-right-circle')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (Requisicion $record): bool => $record->estado === EstadoRequisicion::Despachada
                && self::puede(Permisos::DESPACHAR_REQUISICION))
            ->action(function (Requisicion $record): void {
                app(TransicionarRequisicionService::class)->marcarEnTransito($record, self::userId());

                Notification::make()->title('Requisición en tránsito')->success()->send();
            });
    }

    /**
     * EnTransito → Recibida. Captura cuánto llegó realmente por línea.
     */
    public static function recibir(): Action
    {
        return Action::make('recibir')
            ->label('Recibir')
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('primary')
            ->visible(fn (Requisicion $record): bool => $record->estado === EstadoRequisicion::EnTransito
                && self::puedeRecibir($record))
            ->modalHeading('Confirmar recepción en obra')
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
                            'linea_id'            => $linea->id,
                            'material'            => $linea->material->codigo.' — '.$linea->material->nombre,
                            'cantidad_solicitada' => (string) $linea->cantidad_solicitada,
                            'cantidad'            => (string) $linea->getAttribute($campoDefault),
                        ];

                        if ($conStock) {
                            // Consumibles no almacenables (agua de pipa): nunca
                            // hay stock — no es un faltante, es su flujo normal.
                            if ($linea->material->consumo_inmediato) {
                                $fila['stock'] = 'Compra directa (no almacenable)';
                            } else {
                                $disponible = (string) ($stock->get($linea->material_id) ?? '0');
                                $alcanza = bccomp($disponible, (string) $linea->cantidad_solicitada, 4) >= 0;

                                $fila['stock'] = rtrim(rtrim($disponible, '0'), '.')
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

    private static function userId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}
