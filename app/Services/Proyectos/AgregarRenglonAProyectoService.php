<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Exceptions\Proyectos\ProyectoNoEditableException;
use App\Exceptions\Proyectos\ZonaIncompatibleException;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use Illuminate\Support\Facades\DB;

/**
 * Agrega un renglón nuevo al proyecto.
 *
 * Tres invariantes que defiende este service:
 *
 *  1. ZONA: la ficha referenciada DEBE pertenecer a la zona del
 *     proyecto. Si no, lanza ZonaIncompatibleException.
 *
 *  2. ESTADO: solo proyectos en Borrador permiten agregar renglones.
 *     Una vez Enviada/Aprobada/Rechazada/Vencida, el proyecto está
 *     congelado para protección comercial. Lanza
 *     ProyectoNoEditableException.
 *
 *  3. SNAPSHOT: copia el `precio_venta_cache` de la ficha en
 *     `precio_unitario_snapshot` del renglón al CREAR. Cambios futuros
 *     en la ficha NO afectan este renglón.
 *
 * Después de crear el renglón, recalcula los totales del proyecto
 * (sin esperar al recalculo manual). Todo en una transacción.
 *
 * El campo `orden` se asigna automáticamente como el máximo actual + 1
 * para que los renglones nuevos vayan al final del listado.
 */
final class AgregarRenglonAProyectoService
{
    private const int SCALE_INTERNO = 12;

    private const int SCALE_FINAL = 2;

    public function __construct(
        private readonly CalcularPrecioProyectoService $calculadorTotales = new CalcularPrecioProyectoService,
    ) {}

    /**
     * Agrega un renglón al proyecto.
     *
     * @param string|float $cantidad Cantidad de la unidad de la ficha (ej: 120.5 M²).
     * @param string|null $capitulo Capítulo de presentación (ej: "01 PRELIMINARES").
     * @param string|null $notas Observaciones opcionales.
     *
     * @throws ZonaIncompatibleException Si la ficha es de otra zona.
     * @throws ProyectoNoEditableException Si el proyecto no está en Borrador.
     */
    public function ejecutar(
        Proyecto $proyecto,
        Ficha $ficha,
        string|float $cantidad,
        ?string $capitulo = null,
        ?string $notas = null,
    ): ProyectoRenglon {
        $this->validarEditable($proyecto);
        $this->validarZonaCompatible($proyecto, $ficha);

        return DB::transaction(function () use ($proyecto, $ficha, $cantidad, $capitulo, $notas): ProyectoRenglon {
            // Postgres NO permite SELECT MAX(...) FOR UPDATE — la combinación
            // de lock + función de agregación está prohibida por el estándar.
            // En su lugar lockeamos la fila del PROYECTO durante la transacción.
            // Eso serializa cualquier insert de renglones del mismo proyecto:
            // dos requests concurrentes se ejecutan uno tras otro, sin colisión
            // de números de orden.
            DB::table('proyectos')
                ->where('id', $proyecto->id)
                ->lockForUpdate()
                ->first();

            $cantidadStr = (string) $cantidad;
            $precioSnapshot = (string) $ficha->precio_venta_cache;
            $subtotal = $this->calcularSubtotal($cantidadStr, $precioSnapshot);

            $siguienteOrden = (int) ProyectoRenglon::query()
                ->where('proyecto_id', $proyecto->id)
                ->max('orden') + 1;

            $renglon = ProyectoRenglon::create([
                'proyecto_id'              => $proyecto->id,
                'ficha_id'                 => $ficha->id,
                'orden'                    => $siguienteOrden,
                'capitulo'                 => $capitulo,
                'cantidad'                 => $cantidadStr,
                'precio_unitario_snapshot' => $precioSnapshot,
                'subtotal_cache'           => $subtotal,
                'notas'                    => $notas,
            ]);

            $this->calculadorTotales->recalcular($proyecto);

            return $renglon->fresh() ?? $renglon;
        });
    }

    private function validarEditable(Proyecto $proyecto): void
    {
        if (! $proyecto->estado->permiteEditar()) {
            throw new ProyectoNoEditableException(
                proyectoId: $proyecto->id,
                proyectoCodigo: (string) $proyecto->codigo,
                estadoActual: $proyecto->estado,
            );
        }
    }

    private function validarZonaCompatible(Proyecto $proyecto, Ficha $ficha): void
    {
        if ($proyecto->zona_id === $ficha->zona_id) {
            return;
        }

        $proyecto->loadMissing('zona');
        $ficha->loadMissing('zona');

        throw new ZonaIncompatibleException(
            proyectoId: $proyecto->id,
            proyectoZonaCodigo: (string) ($proyecto->zona->codigo ?? '?'),
            fichaId: $ficha->id,
            fichaZonaCodigo: (string) ($ficha->zona->codigo ?? '?'),
        );
    }

    /**
     * cantidad × precio con bcmath y redondeo half-up a 2 decimales.
     */
    private function calcularSubtotal(string $cantidad, string $precio): string
    {
        $crudo = bcmul($cantidad, $precio, self::SCALE_INTERNO);
        $factor = '0.005';

        return bccomp($crudo, '0', self::SCALE_INTERNO) >= 0
            ? bcadd($crudo, $factor, self::SCALE_FINAL)
            : bcsub($crudo, $factor, self::SCALE_FINAL);
    }
}
