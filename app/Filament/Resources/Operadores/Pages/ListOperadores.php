<?php

declare(strict_types=1);

namespace App\Filament\Resources\Operadores\Pages;

use App\Filament\Resources\Operadores\OperadorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListOperadores extends ListRecords
{
    protected static string $resource = OperadorResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
