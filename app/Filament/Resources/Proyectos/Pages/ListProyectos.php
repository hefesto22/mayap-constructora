<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Enums\EstadoProyecto;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use App\Models\Zona;
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
     * Tabs combinando ZONA y ESTADO para filtrado rápido del listado.
     *
     * Patrón:
     *  - "Todas" = todas las zonas, todos los estados (vista global)
     *  - Una tab por cada combinación zona × estado relevante
     *  - El badge muestra la cantidad de proyectos en esa combinación
     *
     * Para no saturar la barra de tabs, agrupamos:
     *  - Tab "Todas las zonas" (resumen global)
     *  - Tabs por estado: Borrador / Enviadas / Aprobadas / Vencidas
     *  - El filtro de Zona en la tabla permite cruzar dimensiones.
     *
     * Esto da una UX limpia: el usuario tipo entra al sistema y va
     * directo a "Borradores" para terminar lo pendiente, o a
     * "Enviadas" para hacer follow-up con clientes.
     */
    public function getTabs(): array
    {
        $conteos = Proyecto::query()
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $totalGlobal = (int) $conteos->sum();

        $tabs = [
            'todas' => Tab::make('Todas')
                ->icon('heroicon-o-rectangle-stack')
                ->badge($totalGlobal)
                ->badgeColor('gray'),
        ];

        foreach (EstadoProyecto::cases() as $estado) {
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
}
