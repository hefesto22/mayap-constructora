<?php

declare(strict_types=1);

namespace App\Filament\Resources\Materiales\Pages;

use App\Filament\Resources\Materiales\MaterialResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditMaterial extends EditRecord
{
    protected static string $resource = MaterialResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
