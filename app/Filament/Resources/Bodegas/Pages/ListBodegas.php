<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bodegas\Pages;

use App\Filament\Resources\Bodegas\BodegaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBodegas extends ListRecords
{
    protected static string $resource = BodegaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
