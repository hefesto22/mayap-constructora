<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoSolicitudMaquina;
use App\Enums\PrioridadSolicitud;
use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Exceptions\Maquinaria\SolicitudInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\SolicitudMaquina;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ÚNICA puerta del flujo de solicitudes de maquinaria.
 *
 * El encargado pide "esta máquina para tal día a tal hora" y la AGENDA
 * decide al instante:
 *
 *  - Disponible → la solicitud nace AGENDADA con su agendado real en el
 *    calendario. Campanitas: encargados de la obra ("maquinaria agendada",
 *    con hora de llegada) y rol maquinaria (logística).
 *  - No se puede (taller, ya está en esa obra ese día, obra no viva) →
 *    nace PENDIENTE con el motivo, y el rol maquinaria la resuelve:
 *    agendarla en otra fecha / otra máquina, o rechazarla con motivo.
 *
 * La solicitud NUNCA se borra — es historial del proyecto. Reusa
 * AgendarMaquinaService para agendar: cero duplicación de validaciones.
 */
final class SolicitarMaquinaService
{
    /**
     * La máquina solo se auto-agenda en días LIBRES: con un solo
     * compromiso ya existente ese día, la solicitud queda pendiente y
     * MAQUINARIA autoriza el doble uso (son máquinas de jornadas largas —
     * nadie garantiza que se desocupen el mismo día). La resolución
     * manual no tiene límite: ahí decide una persona.
     */
    private const int MAX_AUTOAGENDA_POR_DIA = 1;

    public function __construct(
        private readonly AgendarMaquinaService $agenda,
        private readonly NotificadorMaquinaria $notificador,
    ) {}

    public function crear(
        int $proyectoId,
        int $maquinaId,
        string $fechaDesde,
        string $horaLlegada,
        ?string $fechaHasta = null,
        ?string $notas = null,
        ?int $userId = null,
        PrioridadSolicitud $prioridad = PrioridadSolicitud::Normal,
    ): SolicitudMaquina {
        // Misma máquina + misma obra + mismo día = ya la tiene: esto no es
        // una solicitud, es un error del usuario — se rechaza sin crear
        // nada (ni molestar a maquinaria con una pendiente sin sentido).
        $this->validarNoRepetidaEnLaObra($proyectoId, $maquinaId, $fechaDesde, $fechaHasta);

        return DB::transaction(function () use ($proyectoId, $maquinaId, $fechaDesde, $fechaHasta, $horaLlegada, $notas, $userId, $prioridad): SolicitudMaquina {
            $solicitud = SolicitudMaquina::create([
                'proyecto_id'     => $proyectoId,
                'maquina_id'      => $maquinaId,
                'fecha_necesaria' => $fechaDesde,
                'fecha_hasta'     => $fechaHasta,
                'hora_llegada'    => Carbon::parse($horaLlegada)->format('H:i:s'),
                'estado'          => EstadoSolicitudMaquina::Pendiente,
                'prioridad'       => $prioridad,
                'notas'           => $notas,
                'solicitante_id'  => $userId,
            ]);

            // Día ya comprometido: la agenda NO decide sola — usarla dos
            // veces el mismo día requiere autorización de maquinaria.
            $saturados = $this->diasSaturados($maquinaId, $fechaDesde, $fechaHasta);

            if ($saturados !== []) {
                return $this->marcarPendiente(
                    $solicitud,
                    'La máquina ya está comprometida el '
                    .implode(', ', array_slice($saturados, 0, 3))
                    .' — usarla dos veces el mismo día requiere autorización de maquinaria.',
                    $userId,
                );
            }

            return $this->intentarAgendar($solicitud, $maquinaId, $fechaDesde, $fechaHasta, (string) $solicitud->hora_llegada, $userId);
        });
    }

    private function validarNoRepetidaEnLaObra(int $proyectoId, int $maquinaId, string $fechaDesde, ?string $fechaHasta): void
    {
        $fechasOcupadas = AgendaMaquina::query()
            ->where('maquina_id', $maquinaId)
            ->where('proyecto_id', $proyectoId)
            ->whereBetween('fecha', [$fechaDesde, $fechaHasta ?? $fechaDesde])
            ->orderBy('fecha')
            ->pluck('fecha')
            ->map(fn ($fecha): string => Carbon::parse((string) $fecha)->format('d/m/Y'));

        if ($fechasOcupadas->isEmpty()) {
            return;
        }

        $maquina = Maquina::findOrFail($maquinaId);
        $proyecto = Proyecto::findOrFail($proyectoId);

        throw SolicitudInvalidaException::yaAgendadaEnLaObra(
            $maquina->nombre,
            $proyecto->nombre,
            $fechasOcupadas->take(3)->implode(', '),
        );
    }

    /**
     * Días del rango donde la máquina ya alcanzó el tope de auto-agenda,
     * CON el detalle de cada compromiso (a qué hora llega y a qué obra) —
     * maquinaria decide con los datos enfrente, no a ciegas.
     *
     * @return list<string> "18/07/2026 (llega 10:00 PM a OBRA X)"
     */
    private function diasSaturados(int $maquinaId, string $fechaDesde, ?string $fechaHasta): array
    {
        // array_values: PHPStan exige list<string>, no array<int, string>.
        return array_values(AgendaMaquina::query()
            ->with('proyecto:id,nombre')
            ->where('maquina_id', $maquinaId)
            ->whereBetween('fecha', [$fechaDesde, $fechaHasta ?? $fechaDesde])
            ->orderBy('fecha')
            ->orderBy('hora_entrada')
            ->get()
            ->groupBy(fn (AgendaMaquina $a): string => $a->fecha->toDateString())
            ->filter(fn ($grupo): bool => $grupo->count() >= self::MAX_AUTOAGENDA_POR_DIA)
            ->map(function ($grupo, string $fecha): string {
                $detalle = $grupo
                    ->map(fn (AgendaMaquina $a): string => ($a->horaEntrada12() !== null
                        ? 'llega '.$a->horaEntrada12().' a '
                        : 'en ').$a->proyecto->nombre)
                    ->implode(' y ');

                return Carbon::parse($fecha)->format('d/m/Y')." ({$detalle})";
            })
            ->all());
    }

    /**
     * Corre el RANGO por el agendarLote del calendario (misma lógica:
     * lo agendable se agenda, lo que choca se salta y se reporta):
     *
     *  - Algún día agendado → AGENDADA (si hubo saltados, quedan en el
     *    motivo — el historial dice la verdad completa).
     *  - Ningún día pudo   → PENDIENTE con el porqué, campanita al rol
     *    maquinaria para resolverla.
     */
    private function intentarAgendar(
        SolicitudMaquina $solicitud,
        int $maquinaId,
        string $fechaDesde,
        ?string $fechaHasta,
        string $horaLlegada,
        ?int $userId,
    ): SolicitudMaquina {
        try {
            $resultado = $this->agenda->agendarLote(
                maquinaIds: [$maquinaId],
                proyectoId: $solicitud->proyecto_id,
                desde: $fechaDesde,
                hasta: $fechaHasta ?? $fechaDesde,
                notas: $solicitud->notas,
                userId: $userId,
                horaEntrada: $horaLlegada,
            );
        } catch (AgendaInvalidaException $e) {
            return $this->marcarPendiente($solicitud, $e->getMessage(), $userId);
        }

        if ($resultado['creados'] === 0) {
            return $this->marcarPendiente(
                $solicitud,
                implode(' ', array_slice($resultado['saltados'], 0, 2)),
                $userId,
            );
        }

        $primerAgendadoId = AgendaMaquina::query()
            ->where('maquina_id', $maquinaId)
            ->where('proyecto_id', $solicitud->proyecto_id)
            ->whereBetween('fecha', [$fechaDesde, $fechaHasta ?? $fechaDesde])
            ->orderBy('fecha')
            ->value('id');

        $motivo = "Agendada ({$resultado['creados']} día(s)).";

        if ($resultado['saltados'] !== []) {
            $motivo .= ' Saltados: '.implode(' ', array_slice($resultado['saltados'], 0, 2));
        }

        $solicitud->update([
            'estado'            => EstadoSolicitudMaquina::Agendada,
            'maquina_id'        => $maquinaId,
            'agenda_maquina_id' => $primerAgendadoId,
            'resuelta_por_id'   => $solicitud->solicitante_id === $userId ? null : $userId,
            'resuelta_at'       => now(),
            'motivo'            => $motivo,
        ]);

        // agendarLote ya avisó a los encargados ("maquinaria agendada");
        // aquí va el resultado de la SOLICITUD (solicitante + maquinaria).
        $this->notificador->solicitudResuelta($solicitud, $userId);

        return $solicitud;
    }

    private function marcarPendiente(SolicitudMaquina $solicitud, string $motivo, ?int $userId): SolicitudMaquina
    {
        $solicitud->update(['motivo' => $motivo]);
        $this->notificador->solicitudPendiente($solicitud, $userId);

        return $solicitud;
    }

    /**
     * Resolución MANUAL (rol maquinaria) de una pendiente: agendarla —
     * misma máquina u otra, mismas fechas u otras. Si NINGÚN día pudo,
     * sube como excepción (la UI la muestra) y la solicitud sigue
     * pendiente.
     */
    public function agendar(
        SolicitudMaquina $solicitud,
        string $fechaDesde,
        ?string $fechaHasta = null,
        ?int $maquinaId = null,
        ?string $horaLlegada = null,
        ?int $userId = null,
    ): SolicitudMaquina {
        $this->validarPendiente($solicitud);

        return DB::transaction(function () use ($solicitud, $fechaDesde, $fechaHasta, $maquinaId, $horaLlegada, $userId): SolicitudMaquina {
            $maquinaFinal = $maquinaId ?? $solicitud->maquina_id;
            $horaFinal = $horaLlegada !== null
                ? Carbon::parse($horaLlegada)->format('H:i:s')
                : (string) $solicitud->hora_llegada;

            $resultado = $this->agenda->agendarLote(
                maquinaIds: [$maquinaFinal],
                proyectoId: $solicitud->proyecto_id,
                desde: $fechaDesde,
                hasta: $fechaHasta ?? $fechaDesde,
                notas: $solicitud->notas,
                userId: $userId,
                horaEntrada: $horaFinal,
            );

            if ($resultado['creados'] === 0) {
                throw new AgendaInvalidaException(
                    $resultado['saltados'][0] ?? 'Ningún día del rango se pudo agendar.'
                );
            }

            $primerAgendadoId = AgendaMaquina::query()
                ->where('maquina_id', $maquinaFinal)
                ->where('proyecto_id', $solicitud->proyecto_id)
                ->whereBetween('fecha', [$fechaDesde, $fechaHasta ?? $fechaDesde])
                ->orderBy('fecha')
                ->value('id');

            $motivo = 'Agendada por maquinaria ('.$resultado['creados'].' día(s), desde el '
                .Carbon::parse($fechaDesde)->format('d/m/Y').').';

            if ($resultado['saltados'] !== []) {
                $motivo .= ' Saltados: '.implode(' ', array_slice($resultado['saltados'], 0, 2));
            }

            $solicitud->update([
                'estado'            => EstadoSolicitudMaquina::Agendada,
                'maquina_id'        => $maquinaFinal,
                'agenda_maquina_id' => $primerAgendadoId,
                'resuelta_por_id'   => $userId,
                'resuelta_at'       => now(),
                'motivo'            => $motivo,
            ]);

            $this->notificador->solicitudResuelta($solicitud, $userId);

            return $solicitud;
        });
    }

    public function rechazar(SolicitudMaquina $solicitud, string $motivo, ?int $userId = null): SolicitudMaquina
    {
        $this->validarPendiente($solicitud);

        $solicitud->update([
            'estado'          => EstadoSolicitudMaquina::Rechazada,
            'resuelta_por_id' => $userId,
            'resuelta_at'     => now(),
            'motivo'          => $motivo,
        ]);

        $this->notificador->solicitudResuelta($solicitud, $userId);

        return $solicitud;
    }

    private function validarPendiente(SolicitudMaquina $solicitud): void
    {
        if (! $solicitud->estado->esPendiente()) {
            throw SolicitudInvalidaException::yaResuelta($solicitud->codigo, $solicitud->estado->getLabel());
        }
    }
}
