<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use App\Models\Zona;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Tabs por zona para filtrar la base de precios rápidamente.
     *
     * SOLO tabs por zona (sin "Todas"): los precios son POR ZONA y mostrarlos
     * todos juntos mezcla el mismo material a distinto precio, lo que confunde
     * al usuario. Por defecto se abre en Santa Rosa (ver getDefaultActiveTab).
     *
     *  - Un tab por zona ACTIVA, ordenadas alfabéticamente por código.
     *  - Badge ÁMBAR si la zona tiene items con precios desactualizados
     *    (precio_actualizado_at > 90 días o null) — señal visual para
     *    revisar precios antes de cotizar.
     *  - El tab activo se persiste en query string (?activeTab=TGU).
     *
     * Crece automáticamente con cada zona nueva.
     *
     * Performance: dos queries con GROUP BY (totales + stale), no N+1.
     */
    public function getTabs(): array
    {
        $conteosTotales = Item::query()
            ->selectRaw('zona_id, COUNT(*) as total')
            ->groupBy('zona_id')
            ->pluck('total', 'zona_id');

        $conteosStale = Item::query()
            ->preciosDesactualizados()
            ->selectRaw('zona_id, COUNT(*) as total')
            ->groupBy('zona_id')
            ->pluck('total', 'zona_id');

        $tabs = [];

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

    /**
     * Zona por defecto al abrir la base de precios: Santa Rosa de Copán (SRC).
     * Si esa zona no existe, cae en la primera zona activa. Evita arrancar en
     * una vista global que mezclaría precios de distintas zonas.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        $codigos = Zona::activas()->orderBy('codigo')->pluck('codigo');

        return $codigos->contains('SRC') ? 'SRC' : $codigos->first();
    }
}
