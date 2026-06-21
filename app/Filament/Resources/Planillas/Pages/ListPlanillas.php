<?php

declare(strict_types=1);

namespace App\Filament\Resources\Planillas\Pages;

use App\Filament\Resources\Planillas\PlanillaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlanillas extends ListRecords
{
    protected static string $resource = PlanillaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva planilla'),
        ];
    }
}
