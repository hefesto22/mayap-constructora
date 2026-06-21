<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Pages;

use App\Filament\Resources\Maquinas\MaquinaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaquina extends CreateRecord
{
    protected static string $resource = MaquinaResource::class;
}
