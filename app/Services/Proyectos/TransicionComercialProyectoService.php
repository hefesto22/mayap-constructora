<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\EstadoProyecto;
use App\Exceptions\Proyectos\TransicionEstadoInvalidaException;
use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;

/**
 * Transiciones COMERCIALES de la cotización (sin datos extra): enviar,
 * aprobar, rechazar, marcar vencida y volver a borrador para corregir.
 *
 * Las transiciones de EJECUCIÓN (que requieren fecha/plazo/motivo) viven
 * en sus propios services (IniciarProyectoService, CambiarEstadoEjecucionService).
 *
 * Valida la máquina de estados y registra el cambio en el log de actividad.
 */
final class TransicionComercialProyectoService
{
    public function cambiar(Proyecto $proyecto, EstadoProyecto $destino, ?string $razon = null): Proyecto
    {
        return DB::transaction(function () use ($proyecto, $destino, $razon): Proyecto {
            $fresco = Proyecto::query()
                ->lockForUpdate()
                ->findOrFail($proyecto->id);

            if (! $fresco->estado->puedeTransicionarA($destino)) {
                throw new TransicionEstadoInvalidaException(
                    $fresco->codigo,
                    $fresco->estado,
                    $destino,
                );
            }

            $anterior = $fresco->estado;
            $fresco->update(['estado' => $destino->value]);

            activity('cambio_estado_proyecto')
                ->performedOn($fresco)
                ->withProperties([
                    'estado_anterior' => $anterior->value,
                    'estado_nuevo'    => $destino->value,
                    'razon'           => $razon,
                ])
                ->event('estado_cambiado')
                ->log("Proyecto {$fresco->codigo}: {$anterior->getLabel()} → {$destino->getLabel()}");

            return $fresco->refresh();
        });
    }

    /**
     * Atajo: vuelve una cotización Enviada a Borrador para corregirla.
     */
    public function volverABorrador(Proyecto $proyecto, ?string $razon = null): Proyecto
    {
        return $this->cambiar($proyecto, EstadoProyecto::Borrador, $razon);
    }
}
