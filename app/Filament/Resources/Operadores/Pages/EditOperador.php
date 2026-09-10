<?php

declare(strict_types=1);

namespace App\Filament\Resources\Operadores\Pages;

use App\Filament\Resources\Operadores\OperadorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditOperador extends EditRecord
{
    protected static string $resource = OperadorResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
