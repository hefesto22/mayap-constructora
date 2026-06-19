<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores\Pages;

use App\Filament\Resources\Proveedores\ProveedorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProveedores extends ListRecords
{
    protected static string $resource = ProveedorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
