<?php

declare(strict_types=1);

namespace App\Filament\Resources\HerramientaEquipo\Pages;

use App\Filament\Resources\HerramientaEquipo\HerramientaEquipoResource;
use App\Filament\Resources\Maquinas\MaquinaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Listado de herramienta y equipo — la cara B del toggle de Maquinaria:
 * la pestaña "Maquinaria" navega de regreso al catálogo de máquinas.
 */
class ListHerramientaEquipo extends ListRecords
{
    protected static string $resource = HerramientaEquipoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear herramienta o equipo'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'maquinas' => Tab::make('Maquinaria')
                ->icon('heroicon-o-truck'),
            'herramienta' => Tab::make('Herramienta y equipo')
                ->icon('heroicon-o-wrench-screwdriver'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'herramienta';
    }

    /**
     * La pestaña de maquinaria navega de regreso al catálogo de máquinas.
     */
    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'maquinas') {
            $this->redirect(MaquinaResource::getUrl());

            return;
        }

        parent::updatedActiveTab();
    }
}
