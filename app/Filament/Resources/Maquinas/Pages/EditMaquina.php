<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Pages;

use App\Filament\Resources\Maquinas\MaquinaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMaquina extends EditRecord
{
    protected static string $resource = MaquinaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
