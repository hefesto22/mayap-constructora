<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoMaquina;
use App\Enums\EstadoProyecto;
use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * ÚNICA puerta de creación de agenda de máquina.
 *
 * Reglas que protege:
 *  1. No se agenda en el pasado (lo trabajado se registra como parte).
 *  2. Horas previstas entre 0 y 24 (respalda el CHECK de la DB).
 *  3. La obra debe estar VIVA (en ejecución o pausada).
 *  4. La máquina no puede estar de baja.
 *  5. Choque con mantenimiento: si la máquina está (o estará) en el
 *     taller ese día — rango que cubre la fecha, o mantenimiento abierto
 *     iniciado antes — se bloquea.
 *  6. No duplicar la misma máquina+obra+fecha (respaldo del unique).
 */
final class AgendarMaquinaService
{
    /** Protección: máximo de días por lote (un mes es más que suficiente). */
    private const int MAX_DIAS_LOTE = 31;

    /**
     * Agenda en LOTE: varias máquinas × un rango de días, en un solo
     * guardado. Los días u horas que chocan (mantenimiento, duplicado) se
     * SALTAN y se reportan — lo agendable se agenda igual. Con 30 máquinas
     * diarias, abortar todo por un choque sería contraproducente.
     *
     * @param list<int> $maquinaIds
     *
     * @return array{creados: int, saltados: list<string>}
     */
    public function agendarLote(
        array $maquinaIds,
        int $proyectoId,
        string $desde,
        string $hasta,
        string $horasPrevistas,
        bool $excluirDomingos = true,
        ?string $notas = null,
        ?int $userId = null,
    ): array {
        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta)->startOfDay();

        if ($fin->lt($inicio)) {
            throw AgendaInvalidaException::rangoInvertido($desde, $hasta);
        }

        if ($inicio->diffInDays($fin) >= self::MAX_DIAS_LOTE) {
            throw AgendaInvalidaException::rangoMuyLargo(self::MAX_DIAS_LOTE);
        }

        $creados = 0;
        $saltados = [];

        foreach (CarbonPeriod::create($inicio, $fin) as $dia) {
            if ($excluirDomingos && $dia->isSunday()) {
                continue;
            }

            foreach ($maquinaIds as $maquinaId) {
                try {
                    $this->agendar((int) $maquinaId, $proyectoId, $dia->toDateString(), $horasPrevistas, $notas, $userId);
                    $creados++;
                } catch (AgendaInvalidaException $e) {
                    $saltados[] = $e->getMessage();
                }
            }
        }

        return ['creados' => $creados, 'saltados' => $saltados];
    }

    public function agendar(
        int $maquinaId,
        int $proyectoId,
        string $fecha,
        string $horasPrevistas,
        ?string $notas = null,
        ?int $userId = null,
    ): AgendaMaquina {
        $dia = Carbon::parse($fecha)->startOfDay();

        if ($dia->lt(today())) {
            throw AgendaInvalidaException::fechaPasada($dia->format('d/m/Y'));
        }

        if (bccomp($horasPrevistas, '0', 2) !== 1 || bccomp($horasPrevistas, '24', 2) === 1) {
            throw AgendaInvalidaException::horasInvalidas($horasPrevistas);
        }

        $maquina = Maquina::findOrFail($maquinaId);
        $proyecto = Proyecto::findOrFail($proyectoId);

        if ($maquina->estado === EstadoMaquina::Baja) {
            throw AgendaInvalidaException::maquinaDeBaja($maquina->nombre);
        }

        if (! in_array($proyecto->estado, [EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada], true)) {
            throw AgendaInvalidaException::obraNoViva($proyecto->nombre);
        }

        $this->validarSinMantenimiento($maquina, $dia);
        $this->validarSinDuplicado($maquinaId, $proyectoId, $dia, $maquina->nombre, $proyecto->nombre);

        return AgendaMaquina::create([
            'maquina_id'      => $maquinaId,
            'proyecto_id'     => $proyectoId,
            'fecha'           => $dia->toDateString(),
            'horas_previstas' => $horasPrevistas,
            'notas'           => $notas,
            'user_id'         => $userId,
        ]);
    }

    /**
     * ¿La máquina está o estará en el taller ese día? Un mantenimiento
     * ABIERTO (sin fecha fin) bloquea desde su inicio en adelante: hasta
     * que no se finalice, la máquina no se compromete.
     */
    private function validarSinMantenimiento(Maquina $maquina, Carbon $dia): void
    {
        $enTaller = MantenimientoMaquina::query()
            ->where('maquina_id', $maquina->id)
            ->whereDate('fecha_inicio', '<=', $dia)
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $dia))
            ->exists();

        if ($enTaller) {
            throw AgendaInvalidaException::enMantenimiento($maquina->nombre, $dia->format('d/m/Y'));
        }
    }

    private function validarSinDuplicado(
        int $maquinaId,
        int $proyectoId,
        Carbon $dia,
        string $maquina,
        string $obra,
    ): void {
        $existe = AgendaMaquina::query()
            ->where('maquina_id', $maquinaId)
            ->where('proyecto_id', $proyectoId)
            ->whereDate('fecha', $dia)
            ->exists();

        if ($existe) {
            throw AgendaInvalidaException::yaAgendada($maquina, $obra, $dia->format('d/m/Y'));
        }
    }
}
