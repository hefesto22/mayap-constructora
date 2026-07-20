<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Tables;

use App\Enums\CategoriaCompra;
use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Enums\TipoDocumentoFiscal;
use App\Exceptions\Compras\CompraException;
use App\Filament\Resources\Compras\Actions\AccionFotosFactura;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\User;
use App\Services\Compras\AnularCompraService;
use App\Services\Compras\CompletarCompraService;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Compras\CorregirRecepcionService;
use App\Services\Compras\MarcarPorRecibirService;
use App\Services\Compras\SincronizarRepuestosMantenimientoService;
use App\Services\Compras\VerificarRecepcionService;
use App\Services\Reportes\ActaRecepcionPdfService;
use App\Support\Cantidad;
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
use Illuminate\Database\Eloquent\Builder;

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
                // Categorías (2026-07-20): la factura mixta trae varias —
                // un badge por cada una separa el control de gastos.
                TextColumn::make('categorias')
                    ->label('Categorías')
                    ->badge()
                    ->getStateUsing(fn (Compra $record): array => $record->categorias->all())
                    ->color(fn (CategoriaCompra $state): string => $state->getColor())
                    ->icon(fn (CategoriaCompra $state): string => $state->getIcon())
                    ->formatStateUsing(fn (CategoriaCompra $state): string => $state->getLabel())
                    ->toggleable(),
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
                // Pago y Total son datos de la NEGOCIACIÓN — el encargado de
                // obra solo cuenta bultos: a él no le conciernen.
                TextColumn::make('condicion_pago')
                    ->label('Pago')
                    ->badge()
                    ->color(fn (CondicionPago $state): string => $state->getColor())
                    ->formatStateUsing(fn (CondicionPago $state): string => $state->getLabel())
                    ->visible(fn (): bool => ! Roles::soloEncargado(auth()->user())),
                TextColumn::make('total_cache')
                    ->label('Total')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable()
                    ->visible(fn (): bool => ! Roles::soloEncargado(auth()->user())),
                // Documento fiscal: dato de la negociación, igual que Pago.
                // "—" = compra vieja o borrador que aún no lo declara.
                TextColumn::make('tipo_documento_fiscal')
                    ->label('Documento')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (TipoDocumentoFiscal $state): string => $state->getColor())
                    ->icon(fn (TipoDocumentoFiscal $state): string => $state->getIcon())
                    ->formatStateUsing(fn (TipoDocumentoFiscal $state): string => $state->getLabel())
                    ->tooltip(fn (Compra $record): ?string => $record->numero_factura !== null
                        ? 'N.º '.$record->numero_factura
                        : null)
                    ->toggleable()
                    ->visible(fn (): bool => ! Roles::soloEncargado(auth()->user())),
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable(),
                // Pedido en camino: en rojo si la fecha estimada ya pasó
                // y la compra sigue sin recibirse.
                TextColumn::make('fecha_estimada_llegada')
                    ->label('Llega')
                    ->date('d/M/Y')
                    ->placeholder('—')
                    ->color(fn (Compra $record): string => $record->estado === EstadoCompra::PorRecibir
                        && $record->fecha_estimada_llegada !== null
                        && $record->fecha_estimada_llegada->isPast()
                            ? 'danger'
                            : 'gray')
                    ->toggleable()
                    ->sortable(),
            ])
            ->defaultSort('codigo', 'desc')
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoCompra::options()),
                SelectFilter::make('categorias')
                    ->label('Categoría')
                    ->options(CategoriaCompra::options())
                    // El conjunto es jsonb: filtra las compras cuyo
                    // conjunto CONTIENE la categoría elegida.
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('categorias', $data['value'])
                        : $query),
                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('tipo_documento_fiscal')
                    ->label('Documento fiscal')
                    ->options(TipoDocumentoFiscal::options()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Compra $record): bool => $record->estado === EstadoCompra::Borrador),
                AccionFotosFactura::make(),
                Action::make('registrar')
                    ->label('Registrar')
                    ->icon('heroicon-o-truck')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Registrar la compra')
                    // El texto cambia según la categoría: las compras libres
                    // (taller / otros) no pasan por bodega ni verificación.
                    ->modalDescription(fn (Compra $record): string => $record->esLibre()
                        ? 'La compra pasa a "Por recibir". Es una compra libre: no mueve inventario — cuando llegue, se marca recibida (a crédito genera su cuenta por pagar) y, si viene de un mantenimiento, avisa al taller.'
                        : 'La compra pasa a "Por recibir": se avisa al bodeguero (y al encargado de obra si hay entrega directa) cuánto material debe llegarle. El stock entra cuando ellos VERIFICAN lo recibido.')
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
                // Compras LIBRES (taller/equipo/oficina): sin conteo por
                // línea — todo llegó y se confirma de una vez. Cubre las
                // dos modalidades: mismo día (desde Borrador) y pedido
                // (desde Por recibir, cuando el pedido llega).
                Action::make('recibir_libre')
                    ->label(fn (Compra $record): string => $record->estado === EstadoCompra::PorRecibir
                        ? 'Marcar recibida'
                        : 'Confirmar (recibida)')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar la compra recibida')
                    ->modalDescription('Todo llegó: la compra queda confirmada (a crédito genera su cuenta por pagar). Las compras libres no mueven inventario.')
                    ->modalSubmitActionLabel('Confirmar')
                    ->visible(fn (Compra $record): bool => $record->esLibre()
                        && in_array($record->estado, [EstadoCompra::Borrador, EstadoCompra::PorRecibir], strict: true)
                        && ($u = auth()->user()) instanceof User
                        && Roles::compra($u))
                    ->action(function (Compra $record): void {
                        try {
                            app(ConfirmarCompraService::class)->confirmar($record, self::userId());
                        } catch (CompraException $e) {
                            Notification::make()->title('No se pudo confirmar')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        // Sella el conteo (todo llegó tal cual) y fija la
                        // llegada REAL — la de hoy, no la de la factura.
                        $record->refresh()->loadMissing('lineas');

                        $record->lineas->each(fn (CompraLinea $l) => $l->forceFill([
                            'cantidad_recibida' => $l->cantidad,
                            'verificada_at'     => now(),
                            'verificada_por'    => self::userId(),
                        ])->save());

                        $record->forceFill(['fecha_recepcion' => today()])->save();

                        // Repuestos amarrados a una reparación: deja la
                        // llegada anotada en la bitácora del mantenimiento.
                        app(SincronizarRepuestosMantenimientoService::class)
                            ->llegadaRegistrada($record, self::userId());

                        Notification::make()
                            ->title('Compra recibida y confirmada')
                            ->body('Quedó en el control de gastos; si fue a crédito, la cuenta por pagar ya existe.')
                            ->success()
                            ->send();
                    }),
                Action::make('verificar_recepcion')
                    ->label('Verificar recepción')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('warning')
                    ->visible(function (Compra $record): bool {
                        $user = auth()->user();

                        // Las compras libres no cuentan bultos: usan
                        // "Marcar recibida".
                        return ! $record->esLibre()
                            && $record->estado === EstadoCompra::PorRecibir
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
                                    'material' => $l->nombreLinea(),
                                    'esperado' => Cantidad::corta($l->cantidad),
                                    'recibido' => Cantidad::sinCeros((string) $l->cantidad),
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

                        return ! $record->esLibre()
                            && $user instanceof User
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
                                    'material' => $l->nombreLinea(),
                                    'esperado' => Cantidad::corta($l->cantidad),
                                    'actual'   => Cantidad::corta($l->cantidad_recibida),
                                    'recibido' => Cantidad::sinCeros((string) $l->cantidad_recibida),
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
                                TableColumn::make('Facturado')->width('110px'),
                                TableColumn::make('Conteo actual')->width('110px'),
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
                                TextInput::make('actual')
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
                Action::make('completar')
                    ->label('Completar')
                    ->icon('heroicon-o-lock-closed')
                    ->color('info')
                    // Cierre definitivo: cuadró (facturado = recibido) y la
                    // ventana de corrección venció sin reclamos. Sella la
                    // compra: sin corregir, sin anular, sin editar.
                    ->visible(function (Compra $record): bool {
                        $user = auth()->user();

                        return $user instanceof User
                            && app(CompletarCompraService::class)->puedeCompletar($user, $record);
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Completar la compra')
                    ->modalDescription('Todo cuadró y nadie corrigió el conteo en la ventana de corrección. Al completar, la compra queda SELLADA: ya no se corrige, ni se anula, ni se edita. El acta queda como respaldo.')
                    ->modalSubmitActionLabel('Sí, completar')
                    ->action(function (Compra $record): void {
                        /** @var User $user */
                        $user = auth()->user();

                        try {
                            app(CompletarCompraService::class)->completar($record, $user);
                        } catch (CompraException $e) {
                            Notification::make()->title('No se pudo completar')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Compra completada')
                            ->body("Conciliación cerrada: {$record->codigo} queda sellada.")
                            ->success()
                            ->send();
                    }),
                Action::make('acta_recepcion')
                    ->label('Acta de recepción')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    // Disponible cuando ya hay verificación (confirmada,
                    // completada, o parcial en curso) Y el usuario tiene
                    // alguna porción a su alcance. Vista previa INLINE; el
                    // controller re-valida en servidor.
                    ->visible(function (Compra $record): bool {
                        $user = auth()->user();

                        $hayVerificacion = in_array($record->estado, [EstadoCompra::Confirmada, EstadoCompra::Completada], strict: true)
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
