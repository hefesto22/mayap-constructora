<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Pages;

use App\Filament\Resources\Maquinas\MaquinaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaquinas extends ListRecords
{
    protected static string $resource = MaquinaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva máquina'),
        ];
    }
}
