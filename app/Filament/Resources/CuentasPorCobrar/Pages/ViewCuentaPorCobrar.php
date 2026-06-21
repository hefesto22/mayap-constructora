<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Pages;

use App\Filament\Resources\CuentasPorCobrar\Actions\AccionCobrar;
use App\Filament\Resources\CuentasPorCobrar\CuentaPorCobrarResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCuentaPorCobrar extends ViewRecord
{
    protected static string $resource = CuentaPorCobrarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AccionCobrar::make(),
        ];
    }
}
