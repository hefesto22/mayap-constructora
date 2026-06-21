<?php

declare(strict_types=1);

namespace App\Filament\Resources\Planillas\Pages;

use App\Filament\Resources\Planillas\PlanillaResource;
use App\Models\Planilla;
use App\Services\Planilla\ProcesarPlanillaService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlanilla extends EditRecord
{
    protected static string $resource = PlanillaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Planilla) {
            app(ProcesarPlanillaService::class)->recalcular($record);
        }
    }
}
