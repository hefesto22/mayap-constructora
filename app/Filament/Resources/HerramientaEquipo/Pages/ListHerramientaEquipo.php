<?php

declare(strict_types=1);

namespace App\Filament\Resources\HerramientaEquipo\Pages;

use App\Filament\Resources\HerramientaEquipo\HerramientaEquipoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHerramientaEquipo extends ListRecords
{
    protected static string $resource = HerramientaEquipoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear herramienta o equipo'),
        ];
    }
}
