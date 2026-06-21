<?php

declare(strict_types=1);

namespace App\Filament\Resources\Mantenimientos\Pages;

use App\Filament\Resources\Mantenimientos\MantenimientoMaquinaResource;
use Filament\Resources\Pages\ListRecords;

class ListMantenimientos extends ListRecords
{
    protected static string $resource = MantenimientoMaquinaResource::class;
}
