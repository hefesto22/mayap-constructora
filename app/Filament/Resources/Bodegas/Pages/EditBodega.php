<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bodegas\Pages;

use App\Filament\Resources\Bodegas\BodegaResource;
use Exception;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBodega extends EditRecord
{
    protected static string $resource = BodegaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (): void {
                    if ($this->getRecord()->existencias()->exists()) {
                        throw new Exception(
                            'No se puede eliminar esta bodega: tiene existencias registradas. Márcala como inactiva en su lugar.'
                        );
                    }
                }),
        ];
    }
}
