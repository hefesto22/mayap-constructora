<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoMantenimiento;
use App\Enums\OrigenGastoReparacion;
use App\Enums\TipoCargoObra;
use App\Exceptions\Maquinaria\GastoReparacionInvalidoException;
use App\Filament\Resources\Mantenimientos\MantenimientoMaquinaResource;
use App\Models\BitacoraMantenimiento;
use App\Models\GastoMantenimiento;
use App\Models\MantenimientoMaquina;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Support\Roles;
use Filament\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Lo que costó dejar la máquina trabajando otra vez (decisión Mauricio
 * 2026-09-05).
 *
 * Dos puertas, porque en obra se compra al momento:
 *
 *  - EL ENCARGADO lo anota desde el calendario cuando él mismo compró:
 *    queda PENDIENTE y a recepción le llega la campanita de que hay un
 *    gasto de esa máquina que respaldar.
 *  - RECEPCIÓN lo registra con su factura: nace conciliado.
 *
 * El gasto se carga SIEMPRE a la máquina (es su historial) y anota en
 * qué obra pasó. Cargarlo además al proyecto es una decisión de
 * recepción, nunca automática.
 */
final readonly class RegistrarGastoReparacionService
{
    public function __construct(private RegistrarMovimientoService $inventario) {}

    /**
     * @param string|null $monto Lo que costó. Se IGNORA cuando sale de
     *                           bodega: ahí manda el costo promedio
     *                           del inventario, no lo que alguien
     *                           escriba.
     * @param int|null $materialId Repuesto que salió de bodega.
     * @param string|null $cantidad Cuánto salió.
     */
    public function registrar(
        MantenimientoMaquina $mantenimiento,
        string $descripcion,
        ?string $monto,
        OrigenGastoReparacion $origen,
        ?User $usuario = null,
        ?string $fecha = null,
        ?int $proyectoId = null,
        bool $cargarAProyecto = false,
        ?string $notas = null,
        ?int $materialId = null,
        ?int $bodegaId = null,
        ?string $cantidad = null,
        ?string $montoObra = null,
        ?TipoCargoObra $tipoCargo = null,
    ): GastoMantenimiento {
        return DB::transaction(function () use ($mantenimiento, $descripcion, $monto, $origen, $usuario, $fecha, $proyectoId, $cargarAProyecto, $notas, $materialId, $bodegaId, $cantidad, $montoObra, $tipoCargo): GastoMantenimiento {
            $obraId = $proyectoId ?? $mantenimiento->proyecto_id;
            $deBodega = $origen->esDeBodega();

            if ($deBodega && ($materialId === null || $bodegaId === null || ! is_numeric($cantidad) || (float) $cantidad <= 0.0)) {
                throw GastoReparacionInvalidoException::faltaElMaterial();
            }

            $carga = $cargarAProyecto && $obraId !== null;

            $gasto = GastoMantenimiento::create([
                'mantenimiento_id' => $mantenimiento->id,
                'maquina_id'       => $mantenimiento->maquina_id,
                'proyecto_id'      => $obraId,
                'material_id'      => $deBodega ? $materialId : null,
                'bodega_id'        => $deBodega ? $bodegaId : null,
                'cantidad'         => $deBodega ? $cantidad : null,
                'fecha'            => $fecha ?? today()->toDateString(),
                'descripcion'      => $descripcion,
                // De bodega arranca en 0 y lo fija el movimiento.
                'monto'          => $deBodega ? '0.00' : number_format((float) ($monto ?? 0), 2, '.', ''),
                'origen'         => $origen->value,
                'registrado_por' => $usuario?->id,
                // Solo se le carga a la obra si hay obra Y alguien lo decidió.
                'cargar_a_proyecto' => $carga,
                'monto_obra'        => $carga && $montoObra !== null ? number_format((float) $montoObra, 2, '.', '') : null,
                'tipo_cargo_obra'   => $carga ? ($tipoCargo ?? TipoCargoObra::Gasto)->value : null,
                // Lo que sale de bodega y lo que registra recepción ya
                // tienen respaldo; lo que pagó la obra, no.
                'conciliado_at'  => $origen === OrigenGastoReparacion::Encargado ? null : now(),
                'conciliado_por' => $origen === OrigenGastoReparacion::Encargado ? null : $usuario?->id,
                'notas'          => $notas,
            ]);

            // La salida de bodega es REAL: baja la existencia y su costo
            // promedio es el monto del gasto. Nadie teclea ese número.
            if ($deBodega) {
                $movimiento = $this->inventario->consumoObra(
                    materialId: (int) $materialId,
                    origen: Ubicacion::bodega((int) $bodegaId),
                    cantidad: (string) $cantidad,
                    motivo: 'REPUESTO PARA '.$mantenimiento->codigo,
                    fecha: $fecha ?? today()->toDateString(),
                    userId: $usuario?->id,
                    referencia: $gasto,
                );

                // is_numeric antes de bcmul: es lo que le dice a PHPStan
                // que estas dos son numeric-string (regla de la casa).
                $unitario = $movimiento->costoUnitarioAplicado;
                $usado = (string) $cantidad;

                $gasto->forceFill([
                    'monto' => is_numeric($unitario) && is_numeric($usado)
                        ? number_format((float) bcmul($unitario, $usado, 4), 2, '.', '')
                        : '0.00',
                ])->save();
            }

            if ($mantenimiento->estado === EstadoMantenimiento::EnProceso) {
                BitacoraMantenimiento::create([
                    'mantenimiento_maquina_id' => $mantenimiento->id,
                    'fase'                     => $mantenimiento->fase,
                    'detalle'                  => 'Gasto registrado ('.$gasto->codigo.'): '
                        .$gasto->descripcion.' — L. '.number_format((float) $gasto->monto, 2),
                    'user_id' => $usuario?->id,
                ]);
            }

            if ($gasto->estaPendiente()) {
                $this->avisarPendiente($gasto, $mantenimiento);
            }

            return $gasto;
        });
    }

    /**
     * Campanita a recepción: la obra pagó algo y falta respaldarlo — con
     * su factura y con la decisión de si se le carga al proyecto.
     *
     * notifyNow (síncrono): respeta la transacción del caller.
     */
    private function avisarPendiente(GastoMantenimiento $gasto, MantenimientoMaquina $mantenimiento): void
    {
        $mantenimiento->loadMissing(['maquina:id,nombre', 'proyecto:id,nombre']);

        $notificacion = Notification::make()
            ->title('Gasto de reparación por respaldar — '.$mantenimiento->maquina->nombre)
            ->body(
                'La obra pagó '.$gasto->descripcion.' por L. '.number_format((float) $gasto->monto, 2)
                .($mantenimiento->proyecto !== null ? ' en '.$mantenimiento->proyecto->nombre : '')
                .'. Falta su factura y decidir si se le carga al proyecto.'
            )
            ->icon('heroicon-o-banknotes')
            ->warning()
            ->actions([
                NotificationAction::make('ver_reparacion')
                    ->label('Ver la reparación')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(MantenimientoMaquinaResource::getUrl('view', ['record' => $mantenimiento->getKey()]))
                    ->button(),
            ])
            ->persistent();

        User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::RECEPCION, Roles::MAQUINARIA, Roles::GERENCIA]))
            ->where('is_active', true)
            ->get()
            ->unique('id')
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }
}
