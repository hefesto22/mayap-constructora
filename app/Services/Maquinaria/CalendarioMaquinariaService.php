<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoAsignacion;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\MantenimientoMaquina;
use App\Models\ParteTrabajo;

/**
 * Calendario de maquinaria (G3): arma los eventos que FullCalendar pinta.
 *
 * Filosofía (decisión Mauricio 2026-07-10): el calendario muestra DÍAS y
 * HORAS reales, no barras infinitas. Un vistazo responde: ¿qué trabajó la
 * máquina? (verde, con horas), ¿qué tiene comprometido? (azul programada),
 * ¿está en taller? (ámbar) y ¿qué días está libre? (vacío).
 *
 *  - Parte de trabajo   → evento de 1 día con las horas reales (verde).
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
    private const string COLOR_TRABAJADO = '#16a34a';      // verde — horas reales

    private const string COLOR_PROGRAMADA = '#2563eb';     // azul — agenda futura

    private const string COLOR_ASIGNACION = '#0d9488';     // teal — compromiso

    private const string COLOR_FINALIZADA = '#9ca3af';     // gris

    private const string COLOR_MANTENIMIENTO = '#d97706';  // ámbar

    /**
     * Eventos que tocan el rango visible [desde, hasta].
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventos(
        string $desde,
        string $hasta,
        ?int $maquinaId = null,
        ?int $proyectoId = null,
    ): array {
        return [
            ...$this->partesTrabajo($desde, $hasta, $maquinaId, $proyectoId),
            ...$this->agenda($desde, $hasta, $maquinaId, $proyectoId),
            ...$this->asignaciones($desde, $hasta, $maquinaId, $proyectoId),
            ...$this->mantenimientos($desde, $hasta, $maquinaId),
        ];
    }

    /**
     * Horas REALES trabajadas: un evento por parte, en su día, con horas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function partesTrabajo(string $desde, string $hasta, ?int $maquinaId, ?int $proyectoId): array
    {
        return ParteTrabajo::query()
            ->with(['asignacion.maquina:id,codigo,nombre', 'asignacion.proyecto:id,nombre'])
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($maquinaId, fn ($q) => $q->whereHas('asignacion', fn ($a) => $a->where('maquina_id', $maquinaId)))
            ->when($proyectoId, fn ($q) => $q->whereHas('asignacion', fn ($a) => $a->where('proyecto_id', $proyectoId)))
            ->get()
            ->map(fn (ParteTrabajo $p): array => [
                'id'     => "parte-{$p->id}",
                'title'  => "{$p->asignacion->maquina->nombre} · {$p->asignacion->proyecto->nombre} — ".self::horas($p->horas, $p->horas_extra),
                'start'  => $p->fecha->toDateString(),
                'color'  => self::COLOR_TRABAJADO,
                'allDay' => true,
            ])
            ->all();
    }

    /**
     * Agenda PROGRAMADA: compromiso futuro de un día con horas previstas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function agenda(string $desde, string $hasta, ?int $maquinaId, ?int $proyectoId): array
    {
        return AgendaMaquina::query()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,nombre'])
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($maquinaId, fn ($q) => $q->where('maquina_id', $maquinaId))
            ->when($proyectoId, fn ($q) => $q->where('proyecto_id', $proyectoId))
            ->get()
            ->map(fn (AgendaMaquina $a): array => [
                'id'     => "agenda-{$a->id}",
                'title'  => "🗓 {$a->maquina->nombre} · {$a->proyecto->nombre} — ".self::horas($a->horas_previstas).' prog.',
                'start'  => $a->fecha->toDateString(),
                'color'  => self::COLOR_PROGRAMADA,
                'allDay' => true,
            ])
            ->all();
    }

    /**
     * Asignaciones: rango definido = barra (compromiso contractual);
     * abierta = SOLO marcador el día de inicio.
     *
     * @return array<int, array<string, mixed>>
     */
    private function asignaciones(string $desde, string $hasta, ?int $maquinaId, ?int $proyectoId): array
    {
        return AsignacionMaquina::query()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,nombre'])
            ->where('fecha_inicio', '<=', $hasta)
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', $desde))
            ->when($maquinaId, fn ($q) => $q->where('maquina_id', $maquinaId))
            ->when($proyectoId, fn ($q) => $q->where('proyecto_id', $proyectoId))
            ->get()
            ->map(function (AsignacionMaquina $a): array {
                $abierta = $a->fecha_fin === null;
                $activa = $a->estado === EstadoAsignacion::Activa;

                return [
                    'id'    => "asignacion-{$a->id}",
                    'title' => $abierta
                        ? "📌 {$a->maquina->nombre} → {$a->proyecto->nombre} · desde ".$a->fecha_inicio->format('d/m')
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
                        ? "🔧 {$m->maquina->nombre} — En mantenimiento desde ".$m->fecha_inicio->format('d/m')
                        : "🔧 {$m->maquina->nombre} — Mantenimiento",
                    'start' => $m->fecha_inicio->toDateString(),
                    ...($abierto ? [] : ['end' => $m->fecha_fin->copy()->addDay()->toDateString()]),
                    'color'  => self::COLOR_MANTENIMIENTO,
                    'allDay' => true,
                ];
            })
            ->all();
    }

    /**
     * "8h", "7.5h" o "8h (+2h ext)" — sin ceros de relleno.
     */
    private static function horas(string $horas, string $extra = '0'): string
    {
        $limpiar = static function (string $v): string {
            $texto = number_format((float) $v, 2, '.', '');

            return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
        };

        $texto = $limpiar($horas).'h';

        if (bccomp($extra, '0', 2) === 1) {
            $texto .= ' (+'.$limpiar($extra).'h ext)';
        }

        return $texto;
    }
}
