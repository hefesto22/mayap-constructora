<?php

declare(strict_types=1);

namespace App\Filament\Resources\Materiales\Pages;

use App\Enums\CategoriaItem;
use App\Filament\Resources\Materiales\MaterialResource;
use App\Models\Material;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMateriales extends ListRecords
{
    protected static string $resource = MaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Tabs por categoría física (consistencia con el patrón de Items/Fichas).
     * Solo materiales y herramienta y equipo son inventariables.
     */
    public function getTabs(): array
    {
        $conteos = Material::query()
            ->selectRaw('categoria, COUNT(*) as total')
            ->groupBy('categoria')
            ->pluck('total', 'categoria');

        $tabs = [
            'todos' => Tab::make('Todos')
                ->icon('heroicon-o-rectangle-stack')
                ->badge((int) $conteos->sum())
                ->badgeColor('gray'),
        ];

        foreach ([CategoriaItem::Materiales, CategoriaItem::HerramientaEquipo] as $categoria) {
            $tabs[$categoria->value] = Tab::make($categoria->getLabel())
                ->icon($categoria->getIcon())
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where('categoria', $categoria->value)
                )
                ->badge((int) ($conteos[$categoria->value] ?? 0))
                ->badgeColor($categoria->getColor());
        }

        return $tabs;
    }
}
