<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMantenimiento;
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
 *  - Mantenimiento EN PROCESO con rango → barra ámbar; SIN fecha fin →
 *    marcador de 1 día "En mantenimiento desde dd/mm". Lo FINALIZADO no
 *    se pinta (compromisos, no historia — vive en Mantenimientos).
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
        $hoy = today();

        /** Estadía ABIERTA: llegó y nadie ha marcado que se fue. */
        $abierta = fn ($q) => $q
            ->whereNotNull('llegada_confirmada_at')
            ->whereNull('salida_confirmada_at');

        // Máquinas paradas EN LA OBRA esperando reparación: su estadía se
        // pinta ámbar en vez de sumar un bloque de mantenimiento aparte.
        $averiadasEnSitio = MantenimientoMaquina::query()
            ->where('estado', EstadoMantenimiento::EnProceso)
            ->where('en_sitio', true)
            ->pluck('maquina_id')
            ->flip();

        return AgendaMaquina::query()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,nombre'])
            ->where('fecha', '<=', $hasta)
            // La estadía abierta entra aunque haya empezado antes de la
            // ventana visible: la máquina SIGUE ahí y tiene que verse
            // (2026-09-05 — "saber dónde está la máquina en todo momento").
            ->where(fn ($q) => $q->where('fecha', '>=', $desde)->orWhere($abierta))
            ->when($maquinaId, fn ($q) => $q->where('maquina_id', $maquinaId))
            ->when($proyectoId, fn ($q) => $q->where('proyecto_id', $proyectoId))
            ->when($soloProyectos !== null, fn ($q) => $q->whereIn('proyecto_id', $soloProyectos))
            // La contingencia RESUELTA ("no llegó" con motivo) ya no se
            // pinta: la constancia vive en la bitácora de la obra.
            ->whereNull('no_llego_at')
            // Plan CUMPLIDO desaparece: si ya hay un parte real de esa
            // máquina en esa obra ese día, el evento se retira y el día
            // queda limpio (lo trabajado no se pinta — 2026-07-20). La
            // estadía abierta es la excepción: aunque el día ya tenga su
            // parte, la máquina no se ha ido y la barra sigue.
            ->where(fn ($q) => $q
                ->where($abierta)
                ->orWhereNotExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('partes_trabajo as pt')
                        ->join('asignaciones_maquina as am', 'am.id', '=', 'pt.asignacion_maquina_id')
                        ->whereColumn('am.maquina_id', 'agenda_maquina.maquina_id')
                        ->whereColumn('am.proyecto_id', 'agenda_maquina.proyecto_id')
                        ->whereColumn('pt.fecha', 'agenda_maquina.fecha')
                        ->whereNull('pt.deleted_at');
                }))
            ->get()
            ->map(function (AgendaMaquina $a) use ($hoy, $averiadasEnSitio): array {
                // La fecha pasó y nadie confirmó la llegada: CONTINGENCIA
                // en rojo (decisión Mauricio 2026-07-20). El click la
                // resuelve: llegó tarde o no llegó (con motivo).
                $sinConfirmar = $a->llegada_confirmada_at === null && $a->fecha->isPast() && ! $a->fecha->isToday();

                // Estadía abierta = BARRA violeta del día que llegó hasta
                // hoy. Sin esto, la máquina que se queda en la obra
                // desaparecía del calendario en cuanto se registraba el
                // parte del primer día, y con ella la única forma de
                // cerrar los días siguientes.
                $enObra = $a->llegada_confirmada_at !== null && $a->salida_confirmada_at === null;
                $hastaHoy = $enObra && $a->fecha->lt($hoy)
                    ? $hoy->copy()->addDay()->toDateString()
                    : null;

                // Parada en la obra esperando repuesto: sigue siendo la
                // misma estadía, pero en ámbar y diciéndolo.
                $averiada = $enObra && $averiadasEnSitio->has($a->maquina_id);

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
                        .match (true) {
                            $sinConfirmar => ' — SIN CONFIRMAR',
                            $averiada     => ' — AVERIADA, se repara en la obra',
                            default       => $this->cicloLlegada($a),
                        },
                    'start' => $a->fecha->toDateString(),
                    // Fin EXCLUSIVO en FullCalendar: hoy + 1 día.
                    ...($hastaHoy !== null ? ['end' => $hastaHoy] : []),
                    'color' => match (true) {
                        $sinConfirmar                      => self::COLOR_SIN_CONFIRMAR,
                        $averiada                          => self::COLOR_MANTENIMIENTO,
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
            // La ESTADÍA abierta ya pinta esta misma máquina en esta misma
            // obra, y encima es la que se puede clicar para cerrar el día:
            // la barra de asignación al lado era el mismo hecho dos veces
            // (Mauricio 2026-09-05).
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('agenda_maquina as ag')
                    ->whereColumn('ag.maquina_id', 'asignaciones_maquina.maquina_id')
                    ->whereColumn('ag.proyecto_id', 'asignaciones_maquina.proyecto_id')
                    ->whereNotNull('ag.llegada_confirmada_at')
                    ->whereNull('ag.salida_confirmada_at')
                    ->whereNull('ag.no_llego_at');
            })
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
     * Mantenimientos EN PROCESO: rango definido = barra ámbar; abierto =
     * marcador de 1 día "En mantenimiento desde dd/mm" (una reparación no
     * pinta el mes). Los FINALIZADOS no se pintan (decisión Mauricio
     * 2026-07-22): el calendario mira compromisos; la historia del taller
     * vive en Mantenimientos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mantenimientos(string $desde, string $hasta, ?int $maquinaId): array
    {
        return MantenimientoMaquina::query()
            ->with('maquina:id,codigo,nombre')
            ->where('estado', EstadoMantenimiento::EnProceso)
            ->where('fecha_inicio', '<=', $hasta)
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', $desde))
            ->when($maquinaId, fn ($q) => $q->where('maquina_id', $maquinaId))
            // La reparación EN SITIO no tiene bloque propio: la máquina
            // está en la obra y su barra de estadía ya la muestra — ahí
            // se pinta ámbar y dice "averiada" (Mauricio 2026-09-05).
            ->where('en_sitio', false)
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
    private function cicloLlegada(AgendaMaquina $a): string
    {
        if ($a->llegada_confirmada_at !== null && $a->salida_confirmada_at !== null) {
            return ' — '.$a->llegada_confirmada_at->format('g:i A').' → '.$a->salida_confirmada_at->format('g:i A');
        }

        if ($a->llegada_confirmada_at !== null) {
            // Estadía que ya pasó de su día: lo importante deja de ser la
            // hora en que llegó y pasa a ser que SIGUE AHÍ.
            return $a->fecha->isToday()
                ? ' — llegó '.$a->llegada_confirmada_at->format('g:i A')
                : ' — en obra desde el '.$a->fecha->format('d/m');
        }

        return $a->horaEntrada12() !== null ? " — llega {$a->horaEntrada12()}" : '';
    }
}
