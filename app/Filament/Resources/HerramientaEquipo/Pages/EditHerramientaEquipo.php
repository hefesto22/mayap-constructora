<?php

declare(strict_types=1);

namespace App\Filament\Resources\HerramientaEquipo\Pages;

use App\Filament\Resources\HerramientaEquipo\HerramientaEquipoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHerramientaEquipo extends EditRecord
{
    protected static string $resource = HerramientaEquipoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
