<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\EstadoProyecto;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\CauserResolver;

/**
 * Duplica un proyecto a uno nuevo independiente.
 *
 * USO TÍPICO:
 *  - El cliente regresa después del vencimiento → duplicar para
 *    re-cotizar con precios actuales sin perder histórico.
 *  - Cliente similar pide cotización parecida → duplicar y ajustar.
 *  - Después de "rechazada", duplicar para nueva versión con cambios.
 *
 * COMPORTAMIENTO:
 *  1. El proyecto destino tiene NUEVO codigo auto-generado (PROY-YYYY-#####).
 *  2. El estado SIEMPRE es Borrador (sin importar el del origen).
 *  3. Las fechas se reinician: emisión = hoy, validez = hoy + 30 días.
 *  4. Los renglones se copian con SNAPSHOTS ACTUALES de las fichas
 *     (NO se preservan los snapshots viejos del origen). Esto es lo
 *     que se espera al duplicar para nueva cotización.
 *  5. Cliente, zona, dirección de obra, ISV se mantienen iguales.
 *  6. Auditoría: log_name=duplicado_proyecto con properties que
 *     vinculan origen y destino.
 *
 * VENTAJAS DEL SNAPSHOT NUEVO:
 *  - El duplicado refleja precios actuales (caso real de re-cotizar).
 *  - El proyecto original mantiene sus snapshots intactos (histórico).
 *  - Independencia total: editar el origen NO afecta al duplicado.
 *
 * @phpstan-type ResultadoDuplicacion array{
 *     proyecto_destino: Proyecto,
 *     renglones_copiados: int,
 *     total_origen: string,
 *     total_destino: string,
 * }
 */
final class DuplicarProyectoService
{
    private const int SCALE_INTERNO = 12;

    private const int SCALE_FINAL = 2;

    public function __construct(
        private readonly CalcularPrecioProyectoService $calculadorTotales = new CalcularPrecioProyectoService,
    ) {}

    /**
     * @return array{
     *     proyecto_destino: Proyecto,
     *     renglones_copiados: int,
     *     total_origen: string,
     *     total_destino: string,
     * }
     */
    public function ejecutar(Proyecto $origen): array
    {
        return DB::transaction(function () use ($origen): array {
            $origen->loadMissing('renglones.ficha');

            $hoy = now()->startOfDay();

            $destino = Proyecto::create([
                'zona_id'        => $origen->zona_id,
                'cliente_id'     => $origen->cliente_id,
                'nombre'         => $origen->nombre,
                'descripcion'    => $origen->descripcion,
                'direccion_obra' => $origen->direccion_obra,
                'fecha_emision'  => $hoy,
                'fecha_validez'  => $hoy->copy()->addDays(30),
                'estado'         => EstadoProyecto::Borrador->value,
                'moneda'         => $origen->moneda,
                'aplica_isv'     => $origen->aplica_isv,
                'isv_porcentaje' => $origen->isv_porcentaje,
                'notas'          => $origen->notas,
            ]);

            $renglonesCopiados = 0;

            foreach ($origen->renglones as $renglonOrigen) {
                $ficha = $renglonOrigen->ficha;

                // Si la ficha referenciada fue eliminada después (caso raro
                // pero defensivo), saltamos el renglón con un warning silencioso.
                if ($ficha === null) {
                    continue;
                }

                $cantidad = (string) $renglonOrigen->cantidad;
                $precioActual = (string) $ficha->precio_venta_cache;
                $subtotal = $this->calcularSubtotal($cantidad, $precioActual);

                ProyectoRenglon::create([
                    'proyecto_id'              => $destino->id,
                    'ficha_id'                 => $ficha->id,
                    'orden'                    => $renglonOrigen->orden,
                    'capitulo'                 => $renglonOrigen->capitulo,
                    'cantidad'                 => $cantidad,
                    'precio_unitario_snapshot' => $precioActual,
                    'subtotal_cache'           => $subtotal,
                    'notas'                    => $renglonOrigen->notas,
                ]);

                $renglonesCopiados++;
            }

            $destinoFresco = $this->calculadorTotales->recalcular($destino);

            $this->registrarActividad($origen, $destinoFresco, $renglonesCopiados);

            return [
                'proyecto_destino'   => $destinoFresco,
                'renglones_copiados' => $renglonesCopiados,
                'total_origen'       => (string) $origen->total_cache,
                'total_destino'      => (string) $destinoFresco->total_cache,
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
        Proyecto $origen,
        Proyecto $destino,
        int $renglonesCopiados,
    ): void {
        activity('duplicado_proyecto')
            ->causedBy(CauserResolver::resolve())
            ->performedOn($destino)
            ->withProperties([
                'origen_proyecto_id' => $origen->id,
                'origen_codigo'      => $origen->codigo,
                'destino_codigo'     => $destino->codigo,
                'renglones_copiados' => $renglonesCopiados,
                'total_origen'       => (string) $origen->total_cache,
                'total_destino'      => (string) $destino->total_cache,
            ])
            ->event('duplicado')
            ->log("Proyecto {$origen->codigo} duplicado a {$destino->codigo}");
    }
}
