<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Pages;

use App\Filament\Resources\CuentasPorCobrar\Actions\AccionCobrar;
use App\Filament\Resources\CuentasPorCobrar\CuentaPorCobrarResource;
use Filament\Resources\Pages\ViewRecord;
use Override;

class ViewCuentaPorCobrar extends ViewRecord
{
    protected static string $resource = CuentaPorCobrarResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            AccionCobrar::make(),
        ];
    }
}
