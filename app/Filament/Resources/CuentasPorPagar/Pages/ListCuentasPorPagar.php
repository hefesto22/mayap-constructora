<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar\Pages;

use App\Filament\Resources\CuentasPorPagar\CuentaPorPagarResource;
use Filament\Resources\Pages\ListRecords;

class ListCuentasPorPagar extends ListRecords
{
    protected static string $resource = CuentaPorPagarResource::class;
}
