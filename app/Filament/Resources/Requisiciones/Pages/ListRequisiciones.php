<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Pages;

use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Requisiciones\RequisicionResource;
use App\Models\Requisicion;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRequisiciones extends ListRecords
{
    protected static string $resource = RequisicionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva requisición'),
        ];
    }

    /**
     * Tabs de filtrado rápido por estado del flujo.
     */
    public function getTabs(): array
    {
        return [
            'todas' => Tab::make('Todas')
                ->badge(Requisicion::query()->count()),

            'pendientes' => Tab::make('En curso')
                ->icon('heroicon-o-clock')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotIn('estado', [
                    EstadoRequisicion::Cerrada->value,
                    EstadoRequisicion::Discrepancia->value,
                    EstadoRequisicion::Rechazada->value,
                ]))
                ->badge(Requisicion::query()->whereNotIn('estado', [
                    EstadoRequisicion::Cerrada->value,
                    EstadoRequisicion::Discrepancia->value,
                    EstadoRequisicion::Rechazada->value,
                ])->count())
                ->badgeColor('info'),

            'discrepancia' => Tab::make('Con discrepancia')
                ->icon('heroicon-o-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', EstadoRequisicion::Discrepancia->value))
                ->badge(Requisicion::query()->where('estado', EstadoRequisicion::Discrepancia->value)->count())
                ->badgeColor('danger'),
        ];
    }
}
