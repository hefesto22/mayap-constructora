<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\AsignacionInvalidaException;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona el ciclo de vida de la asignación de una máquina a una obra.
 *
 * Es la única puerta para asignar/liberar máquinas: mantiene en sincronía el
 * estado de la máquina (Disponible ↔ Asignada) con la existencia de una
 * asignación activa. Todo bajo transacción con lock para evitar que dos
 * asignaciones simultáneas tomen la misma máquina.
 */
final class AsignarMaquinaService
{
    /**
     * Asigna una máquina disponible a una obra, congelando la tarifa pactada.
     * Si no se pasa tarifa, hereda la tarifa por defecto de la máquina.
     */
    public function asignar(
        Maquina $maquina,
        int $proyectoId,
        ?string $tarifaPactada = null,
        ?string $fechaInicio = null,
        ?string $notas = null,
    ): AsignacionMaquina {
        return DB::transaction(function () use ($maquina, $proyectoId, $tarifaPactada, $fechaInicio, $notas): AsignacionMaquina {
            // Bloquea la máquina para serializar asignaciones concurrentes.
            $maquinaBloqueada = Maquina::query()
                ->whereKey($maquina->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $maquinaBloqueada->estado->puedeAsignarse()) {
                throw AsignacionInvalidaException::maquinaNoDisponible(
                    $maquinaBloqueada->codigo,
                    $maquinaBloqueada->estado,
                );
            }

            // Defensa adicional contra carrera: no debe existir otra activa.
            $yaActiva = AsignacionMaquina::query()
                ->where('maquina_id', $maquinaBloqueada->id)
                ->activas()
                ->exists();

            if ($yaActiva) {
                throw AsignacionInvalidaException::yaTieneAsignacionActiva($maquinaBloqueada->codigo);
            }

            $asignacion = AsignacionMaquina::create([
                'maquina_id'          => $maquinaBloqueada->id,
                'proyecto_id'         => $proyectoId,
                'tarifa_hora_pactada' => $tarifaPactada ?? (string) $maquinaBloqueada->tarifa_hora,
                'fecha_inicio'        => $fechaInicio ?? now()->toDateString(),
                'estado'              => EstadoAsignacion::Activa,
                'notas'               => $notas,
            ]);

            $maquinaBloqueada->estado = EstadoMaquina::Asignada;
            $maquinaBloqueada->save();

            return $asignacion;
        });
    }

    /**
     * Finaliza una asignación activa y libera la máquina (queda Disponible).
     */
    public function finalizar(AsignacionMaquina $asignacion, ?string $fechaFin = null): void
    {
        DB::transaction(function () use ($asignacion, $fechaFin): void {
            $asignacionBloqueada = AsignacionMaquina::query()
                ->whereKey($asignacion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $asignacionBloqueada->estado->esActiva()) {
                throw AsignacionInvalidaException::asignacionNoActiva($asignacionBloqueada->codigo);
            }

            // Una asignación nunca termina antes de empezar.
            $fecha = $fechaFin !== null ? Carbon::parse($fechaFin) : now();

            $asignacionBloqueada->estado = EstadoAsignacion::Finalizada;
            $asignacionBloqueada->fecha_fin = $fecha->max($asignacionBloqueada->fecha_inicio);
            $asignacionBloqueada->save();

            $maquina = Maquina::query()
                ->whereKey($asignacionBloqueada->maquina_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Solo libera si la máquina estaba asignada (no si entró a
            // mantenimiento por otra vía).
            if ($maquina->estado === EstadoMaquina::Asignada) {
                $maquina->estado = EstadoMaquina::Disponible;
                $maquina->save();
            }
        });
    }

    /**
     * Devuelve una máquina al parque DESDE el catálogo de Maquinaria —
     * la salida de la obra en el mismo lugar donde se ve el estado
     * (decisión Mauricio 2026-08-16). Espejo de
     * MantenimientoService::marcarReparada.
     *
     * Cierra la asignación abierta por la MISMA puerta que la acción
     * Finalizar. Si el estado quedó HUÉRFANO —Asignada sin asignación
     * activa— la libera igual y lo deja anotado: esa máquina no tenía
     * forma de volver al parque.
     *
     * @return AsignacionMaquina|null La asignación cerrada; null si no había ninguna.
     */
    public function liberarDeObra(Maquina $maquina, ?string $fechaFin = null): ?AsignacionMaquina
    {
        return DB::transaction(function () use ($maquina, $fechaFin): ?AsignacionMaquina {
            $maquinaBloqueada = Maquina::query()
                ->whereKey($maquina->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($maquinaBloqueada->estado !== EstadoMaquina::Asignada) {
                throw AsignacionInvalidaException::maquinaNoAsignada(
                    $maquinaBloqueada->codigo,
                    $maquinaBloqueada->estado,
                );
            }

            $abierta = AsignacionMaquina::query()
                ->where('maquina_id', $maquinaBloqueada->id)
                ->where('estado', EstadoAsignacion::Activa->value)
                ->orderByDesc('fecha_inicio')
                ->lockForUpdate()
                ->first();

            if ($abierta !== null) {
                $this->finalizar($abierta, $fechaFin);

                return $abierta->refresh();
            }

            // Estado huérfano: sin asignación que cerrar, la liberación
            // queda en el registro de actividad (quién y cuándo).
            $maquinaBloqueada->estado = EstadoMaquina::Disponible;
            $maquinaBloqueada->save();

            activity('maquinaria')
                ->performedOn($maquinaBloqueada)
                ->withProperties(['fecha' => $fechaFin ?? now()->toDateString()])
                ->event('liberada_sin_asignacion')
                ->log("{$maquinaBloqueada->codigo} volvió al parque sin asignación activa");

            return null;
        });
    }
}
