<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnidadesMedida\Pages;

use App\Filament\Resources\UnidadesMedida\UnidadMedidaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUnidadMedida extends CreateRecord
{
    protected static string $resource = UnidadMedidaResource::class;
}
