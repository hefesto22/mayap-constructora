<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Enums\EstadoProyecto;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProyectos extends ListRecords
{
    protected static string $resource = ProyectoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Tabs por estado, ordenadas por prioridad operativa (no por ciclo
     * de vida). En producción el usuario entra a revisar primero lo que
     * está vivo hoy, no el pipeline comercial:
     *
     *  1. En ejecución  — obras activas, operación diaria
     *  2. Pausada       — requieren decisión (reactivar/cancelar)
     *  3. Aprobada      — listas para arrancar obra
     *  4. Enviada       — follow-up comercial con el cliente
     *  5. Borrador      — cotizaciones pendientes de terminar
     *  6. Vencida       — follow-up perdido, posible re-cotización
     *  7-9. Rechazada / Finalizada / Cancelada — histórico
     *
     * La primera tab (En ejecución) es la activa por defecto.
     * El filtro de Zona en la tabla permite cruzar dimensiones.
     */
    private const ORDEN_PRIORIDAD = [
        EstadoProyecto::EnEjecucion,
        EstadoProyecto::Pausada,
        EstadoProyecto::Aprobada,
        EstadoProyecto::Enviada,
        EstadoProyecto::Borrador,
        EstadoProyecto::Vencida,
        EstadoProyecto::Rechazada,
        EstadoProyecto::Finalizada,
        EstadoProyecto::Cancelada,
    ];

    public function getTabs(): array
    {
        // Conteos con el MISMO scoping del listado (estados permitidos y
        // obras del encargado) — nunca contar lo que el usuario no ve.
        $conteos = ProyectoResource::aplicarVisibilidad(Proyecto::query())
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $tabs = [];

        foreach ($this->estadosVisibles() as $estado) {
            $count = (int) ($conteos[$estado->value] ?? 0);

            $tabs[$estado->value] = Tab::make($estado->getLabel())
                ->icon($estado->getIcon())
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where('estado', $estado->value)
                )
                ->badge($count)
                ->badgeColor($estado->getColor());
        }

        return $tabs;
    }

    /**
     * Tabs visibles = estados otorgados al usuario (vivas siempre + cada
     * estado dado individualmente en la pestaña Personalizados de Roles),
     * respetando el orden de prioridad operativa.
     *
     * @return list<EstadoProyecto>
     */
    private function estadosVisibles(): array
    {
        $visibles = ProyectoResource::estadosVisibles();

        return array_values(array_filter(
            self::ORDEN_PRIORIDAD,
            fn (EstadoProyecto $estado): bool => in_array($estado, $visibles, strict: true),
        ));
    }
}
