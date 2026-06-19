<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Pages;

use App\Filament\Resources\Requisiciones\RequisicionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRequisicion extends CreateRecord
{
    protected static string $resource = RequisicionResource::class;

    /**
     * El solicitante es el usuario autenticado que crea la requisición.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['solicitante_id'] = auth()->id();

        return $data;
    }
}
