<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar\Pages;

use App\Filament\Resources\CuentasPorPagar\Actions\AccionAbonar;
use App\Filament\Resources\CuentasPorPagar\Actions\AccionCambiarVencimiento;
use App\Filament\Resources\CuentasPorPagar\CuentaPorPagarResource;
use Filament\Resources\Pages\ViewRecord;
use Override;

class ViewCuentaPorPagar extends ViewRecord
{
    protected static string $resource = CuentaPorPagarResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            AccionAbonar::make(),
            AccionCambiarVencimiento::make(),
        ];
    }
}
