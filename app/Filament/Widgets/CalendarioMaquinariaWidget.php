<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Actions\AgendarMaquinasAction;
use App\Services\Maquinaria\CalendarioMaquinariaService;
use Filament\Actions\Action;
use Livewire\Attributes\On;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * Widget FullCalendar (plugin oficial de Filament) del calendario de
 * maquinaria — lo renderiza la página CalendarioMaquinaria, que también
 * le manda los filtros. Los eventos salen del CalendarioMaquinariaService
 * (única fuente: asignaciones + mantenimientos).
 */
class CalendarioMaquinariaWidget extends FullCalendarWidget
{
    public ?int $maquinaId = null;

    public ?int $proyectoId = null;

    /**
     * Solo vive dentro de la página del calendario — fuera del dashboard
     * (el descubridor de widgets lo registraría ahí si no).
     */
    public static function canView(): bool
    {
        return false;
    }

    /**
     * Sin acciones de cabecera: las asignaciones y mantenimientos se crean
     * en sus propios recursos — el calendario es la vista, no el capturador.
     *
     * @return array<int, mixed>
     */
    protected function headerActions(): array
    {
        return [];
    }

    /**
     * Click en un evento: NADA. El calendario es para VER — el título ya
     * trae máquina, obra y horas. Navegar a listados con 20-30 eventos
     * diarios confunde (decisión Mauricio 2026-07-13). Sin este override,
     * el plugin intenta montar una acción 'view' que no existe.
     *
     * @param array<string, mixed> $event
     */
    public function onEventClick(array $event): void
    {
        // Intencionalmente vacío.
    }

    /**
     * Acción "agendar" del widget — la monta onDateSelect al arrastrar
     * sobre los días. Misma definición compartida que el botón de la
     * página y la Resource de Agenda.
     */
    public function agendarAction(): Action
    {
        return AgendarMaquinasAction::make()
            ->after(fn () => $this->refreshRecords());
    }

    /**
     * Drag (o click) sobre días del calendario → modal Agendar con el
     * rango YA prellenado. El atajo principal para agendar rápido.
     *
     * @param array<string, mixed>|null $view
     * @param array<string, mixed>|null $resource
     */
    public function onDateSelect(string $start, ?string $end, bool $allDay, ?array $view, ?array $resource): void
    {
        if (! (auth()->user()?->can('Create:AgendaMaquina') ?? false)) {
            return;
        }

        [$inicio, $fin] = $this->calculateTimezoneOffset($start, $end, $allDay);

        $this->mountAction('agendar', [
            'desde' => $inicio->toDateString(),
            // FullCalendar manda el fin EXCLUSIVO en selecciones all-day.
            'hasta' => $fin?->subDay()->toDateString() ?? $inicio->toDateString(),
        ]);
    }

    /**
     * FullCalendar lo llama con el rango visible.
     *
     * @param array{start: string, end: string, timezone: string} $fetchInfo
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchEvents(array $fetchInfo): array
    {
        return app(CalendarioMaquinariaService::class)->eventos(
            substr($fetchInfo['start'], 0, 10),
            substr($fetchInfo['end'], 0, 10),
            $this->maquinaId,
            $this->proyectoId,
        );
    }

    /** La página manda los filtros; el calendario re-pide sus eventos. */
    #[On('calendario-maquinaria-filtrar')]
    public function filtrar(?int $maquinaId = null, ?int $proyectoId = null): void
    {
        $this->maquinaId = $maquinaId;
        $this->proyectoId = $proyectoId;

        $this->refreshRecords();
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return [
            'locale'        => 'es',
            'firstDay'      => 1,
            'initialView'   => 'dayGridMonth',
            'height'        => 'auto',
            'headerToolbar' => [
                'left'   => 'prev,next today',
                'center' => 'title',
                'right'  => 'dayGridMonth,listWeek',
            ],
            'buttonText' => [
                'today' => 'Hoy',
                'month' => 'Mes',
                'list'  => 'Semana',
            ],
        ];
    }
}
