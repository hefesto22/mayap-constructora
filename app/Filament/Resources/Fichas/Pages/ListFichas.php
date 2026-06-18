<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fichas\Pages;

use App\Filament\Resources\Fichas\FichaResource;
use App\Models\Ficha;
use App\Models\Zona;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFichas extends ListRecords
{
    protected static string $resource = FichaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Tabs por zona para filtrar el listado rápidamente.
     *
     * Patrón:
     *  - Tab "Todas" siempre primero, badge = total global de fichas.
     *  - Un tab por zona ACTIVA, ordenadas alfabéticamente por código.
     *    El badge muestra la cantidad de fichas en esa zona.
     *  - Si una zona tiene fichas con cache desactualizado, el badge
     *    se pinta de WARNING (ámbar) para señalarlo visualmente.
     *  - El tab activo se persiste en query string (?activeTab=TGU),
     *    así URLs compartidas conservan el filtro.
     *
     * Crece automáticamente: cuando se agregan zonas nuevas (CEI, COM,
     * etc.), sus tabs aparecen sin tocar este archivo.
     *
     * Performance: dos queries totales (con/sin stale) usando GROUP BY,
     * en lugar de N+1 (una por zona). Aguanta cientos de zonas sin
     * degradación visible.
     */
    public function getTabs(): array
    {
        // Conteo total por zona — una sola query.
        $conteosTotales = Ficha::query()
            ->selectRaw('zona_id, COUNT(*) as total')
            ->groupBy('zona_id')
            ->pluck('total', 'zona_id');

        // Conteo de fichas con cache stale por zona — segunda query.
        $conteosStale = Ficha::query()
            ->cacheDesactualizado()
            ->selectRaw('zona_id, COUNT(*) as total')
            ->groupBy('zona_id')
            ->pluck('total', 'zona_id');

        $totalGlobal = (int) $conteosTotales->sum();
        $totalStaleGlobal = (int) $conteosStale->sum();

        $tabs = [
            'todas' => Tab::make('Todas')
                ->label('Todas las zonas')
                ->icon('heroicon-o-rectangle-stack')
                ->badge($totalGlobal)
                ->badgeColor($totalStaleGlobal > 0 ? 'warning' : 'gray'),
        ];

        $zonas = Zona::activas()->orderBy('codigo')->get();

        foreach ($zonas as $zona) {
            $countZona = (int) ($conteosTotales[$zona->id] ?? 0);
            $staleZona = (int) ($conteosStale[$zona->id] ?? 0);

            $tabs[$zona->codigo] = Tab::make($zona->codigo)
                ->label($zona->codigo)
                ->icon('heroicon-o-map-pin')
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where('zona_id', $zona->id)
                )
                ->badge($countZona)
                ->badgeColor($staleZona > 0 ? 'warning' : 'primary');
        }

        return $tabs;
    }
}
