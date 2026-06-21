<?php

declare(strict_types=1);

namespace App\Filament\Resources\Materiales\Pages;

use App\Filament\Resources\Materiales\MaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaterial extends CreateRecord
{
    protected static string $resource = MaterialResource::class;
}
