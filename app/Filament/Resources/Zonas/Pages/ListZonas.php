<?php

declare(strict_types=1);

namespace App\Filament\Resources\Zonas\Pages;

use App\Filament\Resources\Zonas\ZonaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListZonas extends ListRecords
{
    protected static string $resource = ZonaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
