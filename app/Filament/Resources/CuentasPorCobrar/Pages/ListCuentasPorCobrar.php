<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Pages;

use App\Filament\Resources\CuentasPorCobrar\CuentaPorCobrarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCuentasPorCobrar extends ListRecords
{
    protected static string $resource = CuentaPorCobrarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva cuenta por cobrar'),
        ];
    }
}
