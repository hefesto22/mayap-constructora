<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fichas\Pages;

use App\Filament\Resources\Fichas\FichaResource;
use App\Models\Ficha;
use App\Models\Zona;
use App\Services\Fichas\RecalcularFichasDeZona;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFichas extends ListRecords
{
    protected static string $resource = FichaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->accionRecalcularZona(),
            CreateAction::make(),
        ];
    }

    /**
     * Botón "Recalcular zona": de un solo clic recalcula el cache de precio
     * de TODAS las fichas activas de la zona del tab abierto. Pensado para
     * usarse después de actualizar varios precios de items: el ingeniero
     * propaga los nuevos precios a todas las fichas de la zona sin entrar
     * una por una.
     *
     * - Resuelve la zona desde el tab activo (el listado siempre está
     *   filtrado por zona, no hay vista "Todas").
     * - Confirmación con el conteo exacto de fichas que se van a recalcular,
     *   para que el usuario sepa el alcance antes de propagar.
     * - Solo visible si la zona activa tiene al menos una ficha.
     */
    protected function accionRecalcularZona(): Action
    {
        return Action::make('recalcular_zona')
            ->label(fn (): string => 'Recalcular '.($this->activeTab ?? ''))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->outlined()
            ->visible(fn (): bool => $this->zonaDelTabActivo() instanceof Zona)
            ->requiresConfirmation()
            ->modalHeading('Recalcular todas las fichas de la zona')
            ->modalDescription(function (): string {
                $zona = $this->zonaDelTabActivo();

                if (! $zona instanceof Zona) {
                    return 'No hay una zona seleccionada.';
                }

                $total = Ficha::query()
                    ->where('zona_id', $zona->id)
                    ->where('activa', true)
                    ->count();

                return "Se recalculará el precio de {$total} ficha(s) activa(s) de la zona "
                    ."{$zona->codigo} con los precios actuales de los items. Útil tras "
                    .'actualizar precios. No modifica las fichas de otras zonas.';
            })
            ->modalSubmitActionLabel('Recalcular ahora')
            ->action(function (): void {
                $zona = $this->zonaDelTabActivo();

                if (! $zona instanceof Zona) {
                    Notification::make()
                        ->warning()
                        ->title('Sin zona seleccionada')
                        ->body('Abre el tab de una zona para recalcular sus fichas.')
                        ->send();

                    return;
                }

                $count = app(RecalcularFichasDeZona::class)->ejecutar($zona);

                Notification::make()
                    ->success()
                    ->title("{$count} ficha(s) recalculada(s)")
                    ->body("Las fichas de la zona {$zona->codigo} quedaron al día con los precios actuales.")
                    ->send();
            });
    }

    /**
     * Resuelve la zona correspondiente al tab activo (su código). Devuelve
     * null si no hay tab o el código no mapea a una zona activa.
     */
    private function zonaDelTabActivo(): ?Zona
    {
        $codigo = $this->activeTab;

        if ($codigo === null || $codigo === '') {
            return null;
        }

        return Zona::activas()->where('codigo', $codigo)->first();
    }

    /**
     * Tabs por zona para filtrar el listado rápidamente.
     *
     * Patrón:
     *  - SOLO tabs por zona (sin "Todas"): las fichas son por zona y verlas
     *    todas juntas mezcla precios distintos. Por defecto abre en Santa
     *    Rosa (SRC), ver getDefaultActiveTab.
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
     * Zona por defecto al abrir las fichas: Santa Rosa de Copán (SRC). Si esa
     * zona no existe, la primera zona activa. Evita arrancar en una vista
     * global que mezclaría fichas de distintas zonas.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        $codigos = Zona::activas()->orderBy('codigo')->pluck('codigo');

        return $codigos->contains('SRC') ? 'SRC' : $codigos->first();
    }
}
