<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnidadesMedida\Pages;

use App\Filament\Resources\UnidadesMedida\UnidadMedidaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUnidadMedida extends EditRecord
{
    protected static string $resource = UnidadMedidaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
