<?php

declare(strict_types=1);

namespace App\Filament\Resources\Planillas\Pages;

use App\Filament\Resources\Planillas\PlanillaResource;
use App\Models\Planilla;
use App\Services\Planilla\ProcesarPlanillaService;
use Filament\Resources\Pages\CreateRecord;

class CreatePlanilla extends CreateRecord
{
    protected static string $resource = PlanillaResource::class;

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Planilla) {
            app(ProcesarPlanillaService::class)->recalcular($record);
        }
    }
}
