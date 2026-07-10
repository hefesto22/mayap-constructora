<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMantenimiento;
use App\Filament\Resources\AsignacionesMaquina\AsignacionMaquinaResource;
use App\Filament\Resources\Mantenimientos\MantenimientoMaquinaResource;
use App\Models\AsignacionMaquina;
use App\Models\MantenimientoMaquina;

/**
 * Calendario de maquinaria (G3): arma los eventos que FullCalendar pinta —
 * asignaciones a obra (verde activa / gris finalizada) y mantenimientos
 * (ámbar). Un vistazo responde las tres preguntas del negocio: ¿dónde está
 * cada máquina?, ¿cuándo se libera?, ¿qué huecos hay para alquilar?
 *
 * Los colores son hex fijos (FullCalendar no conoce la paleta de Filament).
 * Las fechas de fin van +1 día: FullCalendar trata el `end` de eventos de
 * día completo como EXCLUSIVO.
 */
final class CalendarioMaquinariaService
{
    private const string COLOR_ACTIVA = '#16a34a';      // verde

    private const string COLOR_FINALIZADA = '#9ca3af';  // gris

    private const string COLOR_MANTENIMIENTO = '#d97706'; // ámbar

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
            ...$this->asignaciones($desde, $hasta, $maquinaId, $proyectoId),
            ...$this->mantenimientos($desde, $hasta, $maquinaId),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function asignaciones(string $desde, string $hasta, ?int $maquinaId, ?int $proyectoId): array
    {
        return AsignacionMaquina::query()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,nombre'])
            ->where('fecha_inicio', '<=', $hasta)
            // Sin fecha_fin = asignación abierta: sigue ocupando la máquina.
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', $desde))
            ->when($maquinaId, fn ($q) => $q->where('maquina_id', $maquinaId))
            ->when($proyectoId, fn ($q) => $q->where('proyecto_id', $proyectoId))
            ->get()
            ->map(fn (AsignacionMaquina $a): array => [
                'id'    => "asignacion-{$a->id}",
                'title' => "{$a->maquina->nombre} · {$a->proyecto->nombre}",
                'start' => $a->fecha_inicio->toDateString(),
                // Abierta: se pinta hasta el fin del rango visible (la
                // máquina sigue ocupada); cerrada: fin exclusivo +1 día.
                'end'   => $a->fecha_fin?->copy()->addDay()->toDateString() ?? $hasta,
                'color' => $a->estado === EstadoAsignacion::Activa
                    ? self::COLOR_ACTIVA
                    : self::COLOR_FINALIZADA,
                // Resource "simple" (edita en modal): el clic lleva al
                // listado con el código ya buscado — un clic del registro.
                'url'    => AsignacionMaquinaResource::getUrl().'?tableSearch='.$a->codigo,
                'allDay' => true,
            ])
            ->all();
    }

    /**
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
            ->map(fn (MantenimientoMaquina $m): array => [
                'id'     => "mantenimiento-{$m->id}",
                'title'  => "🔧 {$m->maquina->nombre} — ".($m->estado === EstadoMantenimiento::EnProceso ? 'En mantenimiento' : 'Mantenimiento'),
                'start'  => $m->fecha_inicio->toDateString(),
                'end'    => $m->fecha_fin?->copy()->addDay()->toDateString() ?? $hasta,
                'color'  => self::COLOR_MANTENIMIENTO,
                'url'    => MantenimientoMaquinaResource::getUrl().'?tableSearch='.$m->codigo,
                'allDay' => true,
            ])
            ->all();
    }
}
