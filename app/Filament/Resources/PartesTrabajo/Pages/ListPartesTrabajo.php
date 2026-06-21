<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartesTrabajo\Pages;

use App\Filament\Resources\PartesTrabajo\ParteTrabajoResource;
use Filament\Resources\Pages\ListRecords;

class ListPartesTrabajo extends ListRecords
{
    protected static string $resource = ParteTrabajoResource::class;
}
