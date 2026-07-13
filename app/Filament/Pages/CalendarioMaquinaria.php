<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\EstadoProyecto;
use App\Filament\Actions\AgendarMaquinasAction;
use App\Models\Maquina;
use App\Models\Proyecto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Calendario de maquinaria (G3): asignaciones a obra y mantenimientos en
 * una sola vista mensual/semanal — dónde está cada máquina, cuándo se
 * libera y qué huecos quedan para alquilar.
 *
 * Acceso vía permiso `View:CalendarioMaquinaria` (lo siembra el
 * RolesInventarioSeeder para maquinaria y gerencia; administrable desde la
 * pantalla de Roles como cualquier otro).
 */
class CalendarioMaquinaria extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Maquinaria';

    protected static ?string $navigationLabel = 'Calendario';

    protected static ?string $title = 'Calendario de maquinaria';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.calendario-maquinaria';

    public ?int $maquinaId = null;

    public ?int $proyectoId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:CalendarioMaquinaria') ?? false;
    }

    /**
     * "Agendar" directo desde el calendario — acción compartida (misma
     * forma y mismo service que la Resource de Agenda y el drag de días).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            AgendarMaquinasAction::make()
                // El widget del plugin escucha este evento y re-pide los
                // eventos del rango visible.
                ->after(fn () => $this->dispatch('filament-fullcalendar--refresh')),
        ];
    }

    /** Cambió un filtro → avisar al widget FullCalendar para que re-pida. */
    public function updated(string $property): void
    {
        $this->dispatch(
            'calendario-maquinaria-filtrar',
            maquinaId: $this->maquinaId,
            proyectoId: $this->proyectoId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'maquinas' => Maquina::query()->orderBy('nombre')->pluck('nombre', 'id'),
            'obras'    => Proyecto::query()
                ->whereIn('estado', [
                    EstadoProyecto::EnEjecucion->value,
                    EstadoProyecto::Pausada->value,
                ])
                ->orderBy('nombre')
                ->pluck('nombre', 'id'),
        ];
    }
}
