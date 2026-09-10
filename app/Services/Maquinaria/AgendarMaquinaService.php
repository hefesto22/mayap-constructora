<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoMaquina;
use App\Enums\EstadoProyecto;
use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use Illuminate\Support\Carbon;

/**
 * ÚNICA puerta de creación de agenda de máquina.
 *
 * La agenda es SIMPLE (decisión Mauricio 2026-07-14): "la máquina llega a
 * las X a la obra Y el día Z". Sin horas estimadas — nunca se sabe cuánto
 * trabajará; las horas REALES las escribe la jornada (parte de trabajo).
 * La hora de entrada es la del aviso "confirma la llegada", y el criterio
 * de no sobre-comprometer una máquina queda en quien agenda (que ve los
 * compromisos del día en el propio formulario).
 *
 * Reglas que protege:
 *  1. No se agenda en el pasado (lo trabajado se registra como parte).
 *  2. La obra debe estar VIVA (en ejecución o pausada).
 *  3. La máquina no puede estar de baja.
 *  4. Choque con mantenimiento: si la máquina está (o estará) en el
 *     taller ese día — rango que cubre la fecha, o mantenimiento abierto
 *     iniciado antes — se bloquea.
 *  5. No duplicar la misma máquina+obra+fecha (respaldo del unique).
 */
final readonly class AgendarMaquinaService
{
    public function __construct(
        private NotificadorMaquinaria $notificador,
    ) {}

    /**
     * Agenda en LOTE: varias máquinas × un rango de días, en un solo
     * guardado. Los días que chocan (mantenimiento, duplicado) se SALTAN
     * y se reportan — lo agendable se agenda igual. Con 30 máquinas
     * diarias, abortar todo por un choque sería contraproducente.
     *
     * @param list<int> $maquinaIds
     *
     * @return array{creados: int, saltados: list<string>}
     */
    public function agendarLote(
        array $maquinaIds,
        int $proyectoId,
        string $dia,
        ?string $notas = null,
        ?int $userId = null,
        ?string $horaEntrada = null,
    ): array {
        // UN SOLO DÍA — el de la llegada (Mauricio, 2026-09-04). Antes esto
        // recorría un rango con CarbonPeriod y creaba una fila por día, o sea
        // pedía adivinar cuánto se iba a quedar la máquina. La permanencia
        // real sale de la asignación: empieza cuando el encargado confirma la
        // llegada y termina cuando registra la salida.
        $fecha = Carbon::parse($dia)->startOfDay()->toDateString();

        $creados = collect();
        $saltados = [];

        foreach ($maquinaIds as $maquinaId) {
            try {
                $creados->push($this->agendar((int) $maquinaId, $proyectoId, $fecha, $notas, $userId, $horaEntrada));
            } catch (AgendaInvalidaException $e) {
                $saltados[] = $e->getMessage();
            }
        }

        // Los encargados de la obra se enteran de una: qué máquina(s) les
        // agendaron y a qué hora llegan (una campanita por lote).
        if ($creados->isNotEmpty()) {
            $this->notificador->maquinariaAgendada(
                Proyecto::findOrFail($proyectoId),
                $creados,
                $userId,
            );
        }

        return ['creados' => $creados->count(), 'saltados' => $saltados];
    }

    public function agendar(
        int $maquinaId,
        int $proyectoId,
        string $fecha,
        ?string $notas = null,
        ?int $userId = null,
        ?string $horaEntrada = null,
    ): AgendaMaquina {
        $dia = Carbon::parse($fecha)->startOfDay();

        if ($dia->lt(today())) {
            throw AgendaInvalidaException::fechaPasada($dia->format('d/m/Y'));
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
        $this->validarSinCompromisoVigente($maquina);
        $this->validarSinDuplicado($maquinaId, $proyectoId, $dia, $maquina->nombre, $proyecto->nombre);

        return AgendaMaquina::create([
            'maquina_id'   => $maquinaId,
            'proyecto_id'  => $proyectoId,
            'fecha'        => $dia->toDateString(),
            'hora_entrada' => $horaEntrada,
            'notas'        => $notas,
            'user_id'      => $userId,
        ]);
    }

    /**
     * ¿La máquina está en el taller ese día? Solo la reparación EN
     * PROCESO bloquea, dentro de su rango: abierta (sin fecha fin)
     * bloquea de su inicio en adelante; con salida prevista, hasta ese
     * día.
     *
     * La FINALIZADA es historia y no bloquea aunque su rango cubra el
     * día — la máquina ya volvió al parque. Antes el día en que se
     * cerraba la reparación (fecha_fin = ese día) seguía rechazando el
     * agendado (2026-08-16).
     */
    private function validarSinMantenimiento(Maquina $maquina, Carbon $dia): void
    {
        $enTaller = MantenimientoMaquina::query()
            ->where('maquina_id', $maquina->id)
            ->where('estado', EstadoMantenimiento::EnProceso->value)
            ->whereDate('fecha_inicio', '<=', $dia)
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $dia))
            ->exists();

        if ($enTaller) {
            throw AgendaInvalidaException::enMantenimiento($maquina->nombre, $dia->format('d/m/Y'));
        }
    }

    /**
     * Una máquina a la vez: no se agenda si ya está comprometida.
     *
     * Desde que la estadía dejó de tener fecha de fin (2026-09-04), el choque
     * ya no se puede detectar por fechas. Una máquina está OCUPADA si:
     *   - llegó a una obra y nadie registró su salida, o
     *   - tiene un agendado pendiente al que todavía no llega.
     *
     * Sin esto la misma retro se podía mandar el viernes a una obra y el
     * sábado a otra, aunque siguiera parada en la primera.
     */
    private function validarSinCompromisoVigente(Maquina $maquina): void
    {
        $vigente = AgendaMaquina::query()
            ->with('proyecto:id,nombre')
            ->where('maquina_id', $maquina->id)
            // Un "no llegó" libera la máquina: el compromiso se cayó.
            ->whereNull('no_llego_at')
            ->whereNull('salida_confirmada_at')
            ->orderBy('fecha')
            ->first();

        if ($vigente === null) {
            return;
        }

        throw $vigente->llegada_confirmada_at !== null
            ? AgendaInvalidaException::maquinaEnOtraObra(
                $maquina->nombre,
                (string) $vigente->proyecto->nombre,
                $vigente->llegada_confirmada_at->format('d/m/Y'),
            )
            : AgendaInvalidaException::maquinaYaComprometida(
                $maquina->nombre,
                (string) $vigente->proyecto->nombre,
                $vigente->fecha->format('d/m/Y'),
            );
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
