<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Maquinaria\CalendarioMaquinariaService;
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
