<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Tables;

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraException;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\User;
use App\Services\Compras\AnularCompraService;
use App\Services\Compras\CorregirRecepcionService;
use App\Services\Compras\MarcarPorRecibirService;
use App\Services\Compras\VerificarRecepcionService;
use App\Services\Reportes\ActaRecepcionPdfService;
use App\Support\Permisos;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ComprasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                TextColumn::make('bodega.codigo')
                    ->label('Bodega')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoCompra $state): string => $state->getColor())
                    ->icon(fn (EstadoCompra $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoCompra $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('condicion_pago')
                    ->label('Pago')
                    ->badge()
                    ->color(fn (CondicionPago $state): string => $state->getColor())
                    ->formatStateUsing(fn (CondicionPago $state): string => $state->getLabel()),
                TextColumn::make('total_cache')
                    ->label('Total')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable(),
            ])
            ->defaultSort('codigo', 'desc')
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoCompra::options()),
                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Compra $record): bool => $record->estado === EstadoCompra::Borrador),
                Action::make('registrar')
                    ->label('Registrar')
                    ->icon('heroicon-o-truck')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Registrar la compra')
                    ->modalDescription('La compra pasa a "Por recibir": se avisa al bodeguero (y al encargado de obra si hay entrega directa) cuánto material debe llegarle. El stock entra cuando ellos VERIFICAN lo recibido.')
                    ->modalSubmitActionLabel('Registrar')
                    // Solo quien COMPRA registra (recepción/gerencia). El
                    // bodeguero y el encargado únicamente VERIFICAN.
                    ->visible(fn (Compra $record): bool => $record->estado === EstadoCompra::Borrador
                        && ($u = auth()->user()) instanceof User
                        && Roles::compra($u))
                    ->action(function (Compra $record): void {
                        try {
                            app(MarcarPorRecibirService::class)->registrar($record, self::userId());
                        } catch (CompraException $e) {
                            Notification::make()->title('No se pudo registrar')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Compra registrada — por recibir')
                            ->body('El punto de llegada ya tiene el reporte de lo que debe recibir.')
                            ->success()
                            ->send();
                    }),
                Action::make('verificar_recepcion')
                    ->label('Verificar recepción')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('warning')
                    ->visible(function (Compra $record): bool {
                        $user = auth()->user();

                        return $record->estado === EstadoCompra::PorRecibir
                            && $user instanceof User
                            && $user->can(Permisos::VERIFICAR_RECEPCION_COMPRA)
                            && app(VerificarRecepcionService::class)->lineasPendientesPara($user, $record)->isNotEmpty();
                    })
                    ->modalHeading('Verificar lo recibido contra la factura')
                    ->modalDescription('Contá los bultos: viene prellenado con lo facturado — solo corregí lo que NO cuadre. Al quedar todo verificado, el stock entra con lo recibido.')
                    ->modalSubmitActionLabel('Guardar verificación')
                    ->fillForm(function (Compra $record): array {
                        /** @var User $user */
                        $user = auth()->user();

                        return [
                            'lineas' => app(VerificarRecepcionService::class)
                                ->lineasPendientesPara($user, $record)
                                ->map(fn (CompraLinea $l): array => [
                                    'linea_id' => $l->id,
                                    'material' => $l->material->nombre,
                                    'esperado' => (string) $l->cantidad,
                                    'recibido' => (string) $l->cantidad,
                                ])
                                ->all(),
                        ];
                    })
                    ->schema([
                        Repeater::make('lineas')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->table([
                                TableColumn::make('Material'),
                                TableColumn::make('Facturado')->width('120px'),
                                TableColumn::make('Recibido')->width('140px'),
                            ])
                            ->compact()
                            ->schema([
                                TextInput::make('material')
                                    ->hiddenLabel()
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('esperado')
                                    ->hiddenLabel()
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('recibido')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->step('any'),
                                Hidden::make('linea_id'),
                            ]),
                    ])
                    ->action(function (Compra $record, array $data): void {
                        /** @var User $user */
                        $user = auth()->user();

                        $recibido = [];

                        foreach ((array) ($data['lineas'] ?? []) as $linea) {
                            if (is_array($linea) && isset($linea['linea_id'], $linea['recibido'])) {
                                $recibido[(int) $linea['linea_id']] = (string) $linea['recibido'];
                            }
                        }

                        try {
                            $estado = app(VerificarRecepcionService::class)->verificar($record, $recibido, $user);
                        } catch (CompraException $e) {
                            Notification::make()->title('No se pudo verificar')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        $estado === EstadoCompra::Confirmada
                            ? Notification::make()
                                ->title('Recepción completa — compra confirmada')
                                ->body('El stock entró con lo recibido. Cualquier diferencia quedó registrada para el reclamo.')
                                ->success()
                                ->send()
                            : Notification::make()
                                ->title('Porción verificada')
                                ->body('Queda pendiente la porción de otro destino para confirmar la compra.')
                                ->info()
                                ->send();
                    }),
                Action::make('corregir_conteo')
                    ->label('Corregir conteo')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    // "Dije 40 y eran 60": re-captura el conteo. Por recibir
                    // = solo números; Confirmada = ajusta inventario (exige
                    // el permiso "Corregir recepción"). El servicio decide.
                    ->visible(function (Compra $record): bool {
                        $user = auth()->user();

                        return $user instanceof User
                            && app(CorregirRecepcionService::class)->lineasCorregiblesPara($user, $record)->isNotEmpty();
                    })
                    ->modalHeading('Corregir el conteo de la recepción')
                    ->modalDescription('Captura lo que REALMENTE llegó. Si la compra ya está confirmada, la diferencia entra o sale del inventario al mismo costo de la factura — la deuda al proveedor no cambia.')
                    ->modalSubmitActionLabel('Aplicar corrección')
                    ->fillForm(function (Compra $record): array {
                        /** @var User $user */
                        $user = auth()->user();

                        return [
                            'lineas' => app(CorregirRecepcionService::class)
                                ->lineasCorregiblesPara($user, $record)
                                ->map(fn (CompraLinea $l): array => [
                                    'linea_id' => $l->id,
                                    'material' => $l->material->nombre,
                                    'esperado' => (string) $l->cantidad,
                                    'recibido' => (string) $l->cantidad_recibida,
                                ])
                                ->all(),
                        ];
                    })
                    ->schema([
                        Repeater::make('lineas')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->table([
                                TableColumn::make('Material'),
                                TableColumn::make('Facturado')->width('120px'),
                                TableColumn::make('Recibido real')->width('140px'),
                            ])
                            ->compact()
                            ->schema([
                                TextInput::make('material')
                                    ->hiddenLabel()
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('esperado')
                                    ->hiddenLabel()
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('recibido')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->step('any'),
                                Hidden::make('linea_id'),
                            ]),
                        Textarea::make('motivo')
                            ->label('Motivo de la corrección')
                            ->placeholder('Ej: se contó el lote incompleto; el proveedor entregó el resto en la tarde.')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (Compra $record, array $data): void {
                        /** @var User $user */
                        $user = auth()->user();

                        $corregido = [];

                        foreach ((array) ($data['lineas'] ?? []) as $linea) {
                            if (is_array($linea) && isset($linea['linea_id'], $linea['recibido'])) {
                                $corregido[(int) $linea['linea_id']] = (string) $linea['recibido'];
                            }
                        }

                        try {
                            app(CorregirRecepcionService::class)->corregir(
                                $record,
                                $corregido,
                                (string) ($data['motivo'] ?? ''),
                                $user,
                            );
                        } catch (CompraException $e) {
                            Notification::make()->title('No se pudo corregir')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Conteo corregido')
                            ->body('El inventario y el acta reflejan el conteo real. La deuda al proveedor no cambió.')
                            ->success()
                            ->send();
                    }),
                Action::make('acta_recepcion')
                    ->label('Acta de recepción')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    // Disponible cuando ya hay verificación (confirmada, o
                    // parcial en curso) Y el usuario tiene alguna porción a
                    // su alcance (recepción/gerencia: completa). Vista
                    // previa INLINE; el controller re-valida en servidor.
                    ->visible(function (Compra $record): bool {
                        $user = auth()->user();

                        $hayVerificacion = $record->estado === EstadoCompra::Confirmada
                            || ($record->estado === EstadoCompra::PorRecibir
                                && $record->lineas()->whereNotNull('verificada_at')->exists());

                        return $hayVerificacion
                            && $user instanceof User
                            && app(ActaRecepcionPdfService::class)->lineasVisibles($record, $user)->isNotEmpty();
                    })
                    ->url(fn (Compra $record): string => route('reportes.acta-recepcion', $record), shouldOpenInNewTab: true),
                Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('danger')
                    ->visible(fn (Compra $record): bool => in_array($record->estado, [EstadoCompra::Confirmada, EstadoCompra::PorRecibir], strict: true)
                        && (auth()->user()?->can(Permisos::ANULAR_COMPRA) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('Anular la compra')
                    ->modalDescription('Revierte el stock que la compra metió (al valor exacto), elimina la cuenta por pagar si no tiene abonos y regresa la requisición si la despachó. Acción definitiva.')
                    ->modalSubmitActionLabel('Sí, anular compra')
                    ->schema([
                        Textarea::make('motivo')
                            ->label('Motivo de la anulación')
                            ->required()
                            ->rows(3)
                            ->placeholder('EJ: FACTURA CAPTURADA CON PRECIO EQUIVOCADO, PROVEEDOR INCORRECTO'),
                    ])
                    ->action(function (Compra $record, array $data): void {
                        try {
                            app(AnularCompraService::class)->anular($record, (string) $data['motivo'], self::userId());
                        } catch (CompraException $e) {
                            Notification::make()->title('No se pudo anular')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Compra anulada')
                            ->body('Inventario y cuenta por pagar revertidos. Todo quedó en la bitácora.')
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * auth()->id() devuelve int|string|null; los Services esperan ?int.
     */
    private static function userId(): ?int
    {
        $userId = auth()->id();

        return is_numeric($userId) ? (int) $userId : null;
    }
}
