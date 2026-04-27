<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnidadesMedida\Pages;

use App\Filament\Resources\UnidadesMedida\UnidadMedidaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUnidadesMedida extends ListRecords
{
    protected static string $resource = UnidadMedidaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
