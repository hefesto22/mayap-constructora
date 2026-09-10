<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Pages;

use App\Filament\Resources\Requisiciones\RequisicionResource;
use App\Models\Requisicion;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;

class ViewRequisicion extends ViewRecord
{
    protected static string $resource = RequisicionResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Requisicion $record): bool => $record->estado->permiteEditarLineas()),
        ];
    }
}
