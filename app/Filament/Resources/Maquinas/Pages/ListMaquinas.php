<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Pages;

use App\Filament\Resources\HerramientaEquipo\HerramientaEquipoResource;
use App\Filament\Resources\Maquinas\MaquinaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Listado de máquinas con TOGGLE hacia Herramienta y equipo (decisión
 * Mauricio 2026-07-19): un solo lugar en el menú (Maquinaria) y arriba
 * las pestañas Maquinaria | Herramienta y equipo. La segunda pestaña
 * NO filtra esta tabla — NAVEGA al catálogo de herramienta (otro
 * modelo), que muestra el mismo toggle invertido.
 */
class ListMaquinas extends ListRecords
{
    protected static string $resource = MaquinaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva máquina'),
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
        return 'maquinas';
    }

    /**
     * La pestaña de herramienta navega al otro catálogo.
     */
    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'herramienta') {
            $this->redirect(HerramientaEquipoResource::getUrl());

            return;
        }

        parent::updatedActiveTab();
    }
}
