<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bodegas\Pages;

use App\Filament\Resources\Bodegas\BodegaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBodega extends CreateRecord
{
    protected static string $resource = BodegaResource::class;
}
