<?php

declare(strict_types=1);

namespace App\Filament\Resources\GastosReparacion\Pages;

use App\Filament\Resources\GastosReparacion\GastoMantenimientoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListGastosMantenimiento extends ListRecords
{
    protected static string $resource = GastoMantenimientoResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
