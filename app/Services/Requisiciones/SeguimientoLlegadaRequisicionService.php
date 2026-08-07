<?php

declare(strict_types=1);

namespace App\Services\Requisiciones;

use App\Enums\EstadoRequisicion;
use App\Models\Compra;
use App\Models\Requisicion;
use App\Models\RequisicionTransicion;
use Illuminate\Support\Carbon;

/**
 * Seguimiento de la llegada del material comprado, del lado de la OBRA
 * (decisión Mauricio 2026-08-07).
 *
 * El problema que resuelve: entre que la obra pide material y que el
 * proveedor lo entrega pasan días, y en ese hueco la obra estaba a ciegas.
 * El encargado pedía para el 10, compras compraba el 8 con entrega el 9, y
 * nadie en Las Palmas se enteraba de que el camión llegaba un día antes.
 * Peor con los atrasos: el ingeniero paraba la cuadrilla sin saber por qué.
 *
 * ÚNICO ESCRITOR de `requisiciones.fecha_estimada_llegada`: esa columna es
 * una copia denormalizada de la fecha que el proveedor prometió en la
 * compra, y existe solo para que el listado la ordene y la pinte sin N+1.
 * Nadie más la toca.
 *
 * Cada cambio de fecha queda en la bitácora de la requisición como un
 * renglón RequisicionCompra → RequisicionCompra (mismo patrón que
 * `TransicionarRequisicionService::reprogramar`): la máquina de estados
 * solo modela avances, pero la trazabilidad del "me dijeron 9, luego 13"
 * es justo lo que hay que poder reclamarle al proveedor.
 */
final readonly class SeguimientoLlegadaRequisicionService
{
    public function __construct(private NotificadorRequisiciones $notificador) {}

    /**
     * La compra quedó registrada "por recibir": la obra se entera de CUÁNDO
     * llega su material y de si eso cae antes o después de cuando lo pidió.
     *
     * No-op si la compra no es directa a obra, no viene de una requisición,
     * o no tiene fecha prometida.
     */
    public function programar(Compra $compra, ?int $userId = null): void
    {
        $requisicion = $this->requisicionEnEspera($compra);

        if ($requisicion === null || $compra->fecha_estimada_llegada === null) {
            return;
        }

        $requisicion->fecha_estimada_llegada = $compra->fecha_estimada_llegada;
        $requisicion->aviso_llegada_obra_at = null;
        $requisicion->save();

        $this->bitacora(
            $requisicion,
            sprintf(
                'Llegada programada para el %s por la compra %s.%s',
                $compra->fecha_estimada_llegada->format('d/m/Y'),
                $compra->codigo,
                $this->comparacionConLaNecesaria($requisicion),
            ),
            $userId,
        );

        $this->notificador->llegadaProgramada($requisicion, $compra, $userId);
    }

    /**
     * El proveedor movió la fecha: adelanto o ATRASO. Es el aviso más
     * importante de todos — un atraso obliga al ingeniero a reprogramar la
     * actividad, y enterarse el día que no llegó el material es tarde.
     */
    public function reprogramar(
        Compra $compra,
        Carbon $anterior,
        string $motivo,
        ?int $userId = null,
    ): void {
        $requisicion = $this->requisicionEnEspera($compra);

        if ($requisicion === null || $compra->fecha_estimada_llegada === null) {
            return;
        }

        $requisicion->fecha_estimada_llegada = $compra->fecha_estimada_llegada;
        // La fecha cambió → el aviso del día de llegada se rearma.
        $requisicion->aviso_llegada_obra_at = null;
        $requisicion->save();

        $this->bitacora(
            $requisicion,
            sprintf(
                'Llegada de %s reprogramada: %s → %s. Motivo: %s',
                $compra->codigo,
                $anterior->format('d/m/Y'),
                $compra->fecha_estimada_llegada->format('d/m/Y'),
                $motivo,
            ),
            $userId,
        );

        $this->notificador->llegadaReprogramada($requisicion, $compra, $anterior, $motivo, $userId);
    }

    /**
     * La requisición enlazada a esta compra, solo si sigue esperando
     * material (Requisición de compra). Si ya se despachó por otro lado no
     * hay nada que seguir.
     */
    private function requisicionEnEspera(Compra $compra): ?Requisicion
    {
        if (! $compra->esDirectaAObra() || $compra->requisicion_id === null) {
            return null;
        }

        $compra->load('requisicion');

        $requisicion = $compra->requisicion;

        return $requisicion->estado === EstadoRequisicion::RequisicionCompra
            ? $requisicion
            : null;
    }

    /**
     * "(la pediste para el 10/08/2026 — llega 1 día tarde)". Es la frase
     * que le dice a la obra si tiene que preocuparse o no.
     */
    private function comparacionConLaNecesaria(Requisicion $requisicion): string
    {
        if ($requisicion->fecha_estimada_llegada === null) {
            return '';
        }

        $necesaria = $requisicion->fecha_necesaria;
        $llegada = $requisicion->fecha_estimada_llegada;
        // (int): diffInDays devuelve float — sin el cast el plural fallaba.
        $dias = (int) $necesaria->diffInDays($llegada, absolute: true);

        if ($llegada->gt($necesaria)) {
            return sprintf(
                ' ⚠ Se necesitaba el %s: llega %d día%s TARDE.',
                $necesaria->format('d/m/Y'),
                $dias,
                $dias === 1 ? '' : 's',
            );
        }

        if ($llegada->lt($necesaria)) {
            return sprintf(
                ' Se necesitaba el %s: llega %d día%s antes.',
                $necesaria->format('d/m/Y'),
                $dias,
                $dias === 1 ? '' : 's',
            );
        }

        return ' Justo el día que se necesitaba.';
    }

    private function bitacora(Requisicion $requisicion, string $nota, ?int $userId): void
    {
        RequisicionTransicion::create([
            'requisicion_id' => $requisicion->id,
            'estado_origen'  => EstadoRequisicion::RequisicionCompra,
            'estado_destino' => EstadoRequisicion::RequisicionCompra,
            'user_id'        => $userId,
            'nota'           => $nota,
        ]);
    }
}
