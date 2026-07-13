<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgendaMaquina\Pages;

use App\Filament\Actions\AgendarMaquinasAction;
use App\Filament\Resources\AgendaMaquina\AgendaMaquinaResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAgendaMaquina extends ManageRecords
{
    protected static string $resource = AgendaMaquinaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Acción compartida con el calendario: lote de máquinas × días
            // vía AgendarMaquinaService (choques se saltan y reportan).
            AgendarMaquinasAction::make()->label('Agendar'),
        ];
    }
}
