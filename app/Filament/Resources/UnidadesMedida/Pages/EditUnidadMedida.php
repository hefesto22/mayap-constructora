<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnidadesMedida\Pages;

use App\Filament\Resources\UnidadesMedida\UnidadMedidaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditUnidadMedida extends EditRecord
{
    protected static string $resource = UnidadMedidaResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
