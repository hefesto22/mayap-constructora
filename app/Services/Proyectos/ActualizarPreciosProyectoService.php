<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Exceptions\Proyectos\ProyectoNoEditableException;
use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\CauserResolver;

/**
 * Actualiza todos los snapshots de precios de un proyecto a los
 * precios actuales de las fichas referenciadas.
 *
 * USO TÍPICO: el ingeniero creó una cotización en mayo. En agosto
 * el cliente regresa interesado, pero los precios de los items de
 * la base subieron. Antes de re-enviar la cotización, se actualizan
 * los snapshots para que los montos reflejen los precios vigentes.
 *
 * QUE HACE EL SERVICE:
 *  1. Para cada renglón del proyecto:
 *     a. Lee el `precio_venta_cache` ACTUAL de la ficha referenciada.
 *     b. Sobrescribe `precio_unitario_snapshot` del renglón.
 *     c. Recalcula `subtotal_cache` = cantidad × snapshot nuevo.
 *  2. Recalcula los totales del proyecto (subtotal, ISV, total).
 *  3. Loguea en activitylog (log_name=actualizacion_precios) con
 *     un resumen del cambio (cuántos renglones, total anterior, nuevo).
 *
 * RESTRICCIÓN: solo proyectos en Borrador. Una vez enviada al cliente,
 * los precios están comprometidos. Para refrescar precios después de
 * enviar, el flujo correcto es duplicar y actualizar el duplicado.
 *
 * IDEMPOTENTE: correr dos veces seguido produce el mismo resultado
 * (los snapshots ya estarán al día tras la primera ejecución).
 *
 * @phpstan-type ResultadoActualizacion array{
 *     renglones_actualizados: int,
 *     total_anterior: string,
 *     total_nuevo: string,
 *     diferencia: string,
 * }
 */
final class ActualizarPreciosProyectoService
{
    private const int SCALE_INTERNO = 12;

    private const int SCALE_FINAL = 2;

    public function __construct(
        private readonly CalcularPrecioProyectoService $calculadorTotales = new CalcularPrecioProyectoService,
    ) {}

    /**
     * @return array{
     *     renglones_actualizados: int,
     *     total_anterior: string,
     *     total_nuevo: string,
     *     diferencia: string,
     * }
     *
     * @throws ProyectoNoEditableException Si el proyecto no está en Borrador.
     */
    public function ejecutar(Proyecto $proyecto): array
    {
        if (! $proyecto->estado->permiteEditar()) {
            throw new ProyectoNoEditableException(
                proyectoId: $proyecto->id,
                proyectoCodigo: (string) $proyecto->codigo,
                estadoActual: $proyecto->estado,
            );
        }

        return DB::transaction(function () use ($proyecto): array {
            $proyecto->loadMissing('renglones.ficha');

            $totalAnterior = (string) $proyecto->total_cache;
            $renglonesActualizados = 0;

            foreach ($proyecto->renglones as $renglon) {
                $ficha = $renglon->ficha;

                if ($ficha === null) {
                    continue;
                }

                $precioActual = (string) $ficha->precio_venta_cache;
                $cantidad = (string) $renglon->cantidad;
                $subtotalNuevo = $this->calcularSubtotal($cantidad, $precioActual);

                // Skip si ya están al día — evita writes innecesarias.
                if (
                    bccomp($precioActual, (string) $renglon->precio_unitario_snapshot, self::SCALE_FINAL) === 0
                    && bccomp($subtotalNuevo, (string) $renglon->subtotal_cache, self::SCALE_FINAL) === 0
                ) {
                    continue;
                }

                $renglon->forceFill([
                    'precio_unitario_snapshot' => $precioActual,
                    'subtotal_cache'           => $subtotalNuevo,
                ])->save();

                $renglonesActualizados++;
            }

            $proyectoFresco = $this->calculadorTotales->recalcular($proyecto);
            $totalNuevo = (string) $proyectoFresco->total_cache;
            $diferencia = bcsub($totalNuevo, $totalAnterior, self::SCALE_FINAL);

            $this->registrarActividad(
                $proyectoFresco,
                $renglonesActualizados,
                $totalAnterior,
                $totalNuevo,
                $diferencia,
            );

            return [
                'renglones_actualizados' => $renglonesActualizados,
                'total_anterior'         => $totalAnterior,
                'total_nuevo'            => $totalNuevo,
                'diferencia'             => $diferencia,
            ];
        });
    }

    private function calcularSubtotal(string $cantidad, string $precio): string
    {
        $crudo = bcmul($cantidad, $precio, self::SCALE_INTERNO);
        $factor = '0.005';

        return bccomp($crudo, '0', self::SCALE_INTERNO) >= 0
            ? bcadd($crudo, $factor, self::SCALE_FINAL)
            : bcsub($crudo, $factor, self::SCALE_FINAL);
    }

    private function registrarActividad(
        Proyecto $proyecto,
        int $renglonesActualizados,
        string $totalAnterior,
        string $totalNuevo,
        string $diferencia,
    ): void {
        activity('actualizacion_precios')
            ->causedBy(CauserResolver::resolve())
            ->performedOn($proyecto)
            ->withProperties([
                'codigo'                 => $proyecto->codigo,
                'renglones_actualizados' => $renglonesActualizados,
                'total_anterior'         => $totalAnterior,
                'total_nuevo'            => $totalNuevo,
                'diferencia'             => $diferencia,
            ])
            ->event('precios_actualizados')
            ->log("Precios actualizados en proyecto {$proyecto->codigo}");
    }
}
