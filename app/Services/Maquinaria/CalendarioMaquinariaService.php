<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoAsignacion;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\MantenimientoMaquina;

/**
 * Calendario de maquinaria (G3): arma los eventos que FullCalendar pinta.
 *
 * Filosofía (decisión Mauricio 2026-07-10, afinada 2026-07-20): el
 * calendario mira HACIA ADELANTE — compromisos, no historia. Lo YA
 * TRABAJADO no se pinta (saturaba la vista); esa historia vive en Partes
 * de Trabajo. Un vistazo responde: ¿qué hay comprometido? (azul
 * programada / violeta trabajando), ¿está en taller? (ámbar) y ¿qué
 * días está libre? (vacío).
 *
 *  - Parte de trabajo   → NO genera evento: al registrar la jornada, el
 *    azul/violeta de ese día se retira y el día queda limpio.
 *  - Agenda programada  → evento de 1 día con horas previstas (azul).
 *  - Asignación con rango definido → barra teal (compromiso contractual);
 *    finalizada → gris. SIN fecha fin → solo un marcador el día de inicio
 *    ("desde dd/mm"), nunca una barra que pinte el mes completo.
 *  - Mantenimiento con rango → barra ámbar; SIN fecha fin → marcador de
 *    1 día "En mantenimiento desde dd/mm".
 *
 * Los colores son hex fijos (FullCalendar no conoce la paleta de Filament).
 * Las fechas de fin van +1 día: FullCalendar trata el `end` de eventos de
 * día completo como EXCLUSIVO.
 */
final class CalendarioMaquinariaService
{
    private const string COLOR_PROGRAMADA = '#2563eb';     // azul — agenda futura

    private const string COLOR_SIN_CONFIRMAR = '#dc2626';  // rojo — la fecha pasó y nadie confirmó

    private const string COLOR_TRABAJANDO = '#7c3aed';     // violeta — llegó y sigue en la obra

    private const string COLOR_ASIGNACION = '#0d9488';     // teal — compromiso

    private const string COLOR_FINALIZADA = '#9ca3af';     // gris

    private const string COLOR_MANTENIMIENTO = '#d97706';  // ámbar

    /**
     * Eventos que tocan el rango visible [desde, hasta].
     *
     * `$soloProyectos` acota TODO a esas obras (el encargado ve solo las
     * suyas — mismo alcance que requisiciones y solicitudes); null = sin
     * límite (maquinaria/gerencia). Los mantenimientos no pertenecen a
     * una obra: en la vista acotada no aparecen (el taller es asunto del
     * rol maquinaria).
     *
     * @param list<int>|null $soloProyectos
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventos(
        string $desde,
        string $hasta,
        ?int $maquinaId = null,
        ?int $proyectoId = null,
        ?array $soloProyectos = null,
    ): array {
        return [
            ...$this->agenda($desde, $hasta, $maquinaId, $proyectoId, $soloProyectos),
            ...$this->asignaciones($desde, $hasta, $maquinaId, $proyectoId, $soloProyectos),
            ...($soloProyectos === null ? $this->mantenimientos($desde, $hasta, $maquinaId) : []),
        ];
    }

    /**
     * Agenda PROGRAMADA: compromiso futuro de un día con hora de llegada.
     *
     * @param list<int>|null $soloProyectos
     *
     * @return array<int, array<string, mixed>>
     */
    private function agenda(string $desde, string $hasta, ?int $maquinaId, ?int $proyectoId, ?array $soloProyectos): array
    {
        return AgendaMaquina::query()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,nombre'])
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($maquinaId, fn ($q) => $q->where('maquina_id', $maquinaId))
            ->when($proyectoId, fn ($q) => $q->where('proyecto_id', $proyectoId))
            ->when($soloProyectos !== null, fn ($q) => $q->whereIn('proyecto_id', $soloProyectos))
            // La contingencia RESUELTA ("no llegó" con motivo) ya no se
            // pinta: la constancia vive en la bitácora de la obra.
            ->whereNull('no_llego_at')
            // Plan CUMPLIDO desaparece: si ya hay un parte real de esa
            // máquina en esa obra ese día, el evento se retira y el día
            // queda limpio (lo trabajado no se pinta — 2026-07-20).
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('partes_trabajo as pt')
                    ->join('asignaciones_maquina as am', 'am.id', '=', 'pt.asignacion_maquina_id')
                    ->whereColumn('am.maquina_id', 'agenda_maquina.maquina_id')
                    ->whereColumn('am.proyecto_id', 'agenda_maquina.proyecto_id')
                    ->whereColumn('pt.fecha', 'agenda_maquina.fecha')
                    ->whereNull('pt.deleted_at');
            })
            ->get()
            ->map(function (AgendaMaquina $a): array {
                // La fecha pasó y nadie confirmó la llegada: CONTINGENCIA
                // en rojo (decisión Mauricio 2026-07-20). El click la
                // resuelve: llegó tarde o no llegó (con motivo).
                $sinConfirmar = $a->llegada_confirmada_at === null && $a->fecha->isPast() && ! $a->fecha->isToday();

                return [
                    'id' => "agenda-{$a->id}",
                    // La agenda es simple: a qué hora LLEGA y a dónde (en
                    // AM/PM — el formato de la constructora). El ciclo lo
                    // cuenta el COLOR (decisión Mauricio 2026-07-16): azul =
                    // plan, VIOLETA = llegó (y sigue violeta al terminar,
                    // hasta que se registren las horas/litros: ahí este
                    // evento se retira y el día queda limpio), ROJO = la
                    // fecha pasó sin confirmar. Sin emojis — los datos
                    // hablan solos.
                    'title' => "{$a->maquina->nombre} · {$a->proyecto->nombre}"
                        .($sinConfirmar ? ' — SIN CONFIRMAR' : self::cicloLlegada($a)),
                    'start' => $a->fecha->toDateString(),
                    'color' => match (true) {
                        $sinConfirmar                      => self::COLOR_SIN_CONFIRMAR,
                        $a->llegada_confirmada_at === null => self::COLOR_PROGRAMADA,
                        default                            => self::COLOR_TRABAJANDO,
                    },
                    'allDay' => true,
                ];
            })
            ->all();
    }

    /**
     * Asignaciones: rango definido = barra (compromiso contractual);
     * abierta = SOLO marcador el día de inicio.
     *
     * @param list<int>|null $soloProyectos
     *
     * @return array<int, array<string, mixed>>
     */
    private function asignaciones(string $desde, string $hasta, ?int $maquinaId, ?int $proyectoId, ?array $soloProyectos): array
    {
        return AsignacionMaquina::query()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,nombre'])
            ->where('fecha_inicio', '<=', $hasta)
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', $desde))
            ->when($maquinaId, fn ($q) => $q->where('maquina_id', $maquinaId))
            ->when($proyectoId, fn ($q) => $q->where('proyecto_id', $proyectoId))
            ->when($soloProyectos !== null, fn ($q) => $q->whereIn('proyecto_id', $soloProyectos))
            // La asignación FINALIZADA de UN solo día que ya tiene su
            // parte registrado ese día es historia trabajada — es la
            // administrativa (p. ej. la automática al registrar la
            // jornada desde el calendario) y no se pinta.
            ->whereNot(fn ($q) => $q
                ->where('estado', EstadoAsignacion::Finalizada->value)
                ->whereColumn('fecha_fin', 'fecha_inicio')
                ->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('partes_trabajo as pt')
                        ->join('asignaciones_maquina as am2', 'am2.id', '=', 'pt.asignacion_maquina_id')
                        ->whereColumn('am2.maquina_id', 'asignaciones_maquina.maquina_id')
                        ->whereColumn('am2.proyecto_id', 'asignaciones_maquina.proyecto_id')
                        ->whereColumn('pt.fecha', 'asignaciones_maquina.fecha_inicio')
                        ->whereNull('pt.deleted_at');
                }))
            ->get()
            ->map(function (AsignacionMaquina $a): array {
                $abierta = $a->fecha_fin === null;
                $activa = $a->estado === EstadoAsignacion::Activa;

                return [
                    'id'    => "asignacion-{$a->id}",
                    'title' => $abierta
                        ? "{$a->maquina->nombre} → {$a->proyecto->nombre} · desde ".$a->fecha_inicio->format('d/m')
                        : "{$a->maquina->nombre} · {$a->proyecto->nombre}",
                    'start' => $a->fecha_inicio->toDateString(),
                    // Abierta: marcador de UN día (sin end). Cerrada: fin
                    // exclusivo +1 día.
                    ...($abierta ? [] : ['end' => $a->fecha_fin->copy()->addDay()->toDateString()]),
                    'color'  => $activa ? self::COLOR_ASIGNACION : self::COLOR_FINALIZADA,
                    'allDay' => true,
                ];
            })
            ->all();
    }

    /**
     * Mantenimientos: rango definido = barra ámbar; abierto = marcador de
     * 1 día "En mantenimiento desde dd/mm" (una reparación no pinta el mes).
     *
     * @return array<int, array<string, mixed>>
     */
    private function mantenimientos(string $desde, string $hasta, ?int $maquinaId): array
    {
        return MantenimientoMaquina::query()
            ->with('maquina:id,codigo,nombre')
            ->where('fecha_inicio', '<=', $hasta)
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', $desde))
            ->when($maquinaId, fn ($q) => $q->where('maquina_id', $maquinaId))
            ->get()
            ->map(function (MantenimientoMaquina $m): array {
                $abierto = $m->fecha_fin === null;

                return [
                    'id'    => "mantenimiento-{$m->id}",
                    'title' => $abierto
                        ? "{$m->maquina->nombre} — En mantenimiento desde ".$m->fecha_inicio->format('d/m')
                        : "{$m->maquina->nombre} — Mantenimiento",
                    'start' => $m->fecha_inicio->toDateString(),
                    ...($abierto ? [] : ['end' => $m->fecha_fin->copy()->addDay()->toDateString()]),
                    'color'  => self::COLOR_MANTENIMIENTO,
                    'allDay' => true,
                ];
            })
            ->all();
    }

    /**
     * El tramo del título que narra el ciclo de la llegada:
     * plan ("llega 8:00 AM"), adentro ("llegó 8:15 AM") o cerrado
     * ("8:15 AM → 1:00 PM").
     */
    private static function cicloLlegada(AgendaMaquina $a): string
    {
        if ($a->llegada_confirmada_at !== null && $a->salida_confirmada_at !== null) {
            return ' — '.$a->llegada_confirmada_at->format('g:i A').' → '.$a->salida_confirmada_at->format('g:i A');
        }

        if ($a->llegada_confirmada_at !== null) {
            return ' — llegó '.$a->llegada_confirmada_at->format('g:i A');
        }

        return $a->horaEntrada12() !== null ? " — llega {$a->horaEntrada12()}" : '';
    }
}
