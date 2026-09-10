<?php

declare(strict_types=1);

namespace App\Filament\Resources\Empleados\Pages;

use App\Filament\Resources\Empleados\EmpleadoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListEmpleados extends ListRecords
{
    protected static string $resource = EmpleadoResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo empleado'),
        ];
    }
}
