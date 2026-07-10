<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones\Pages;

use App\Filament\Resources\Requisiciones\RequisicionResource;
use App\Models\Requisicion;
use App\Services\Requisiciones\NotificadorRequisiciones;
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

    /**
     * Campanita a bodega: hay una requisición nueva por autorizar.
     */
    protected function afterCreate(): void
    {
        $requisicion = $this->getRecord();

        if ($requisicion instanceof Requisicion) {
            app(NotificadorRequisiciones::class)->nuevaSolicitud($requisicion, auth()->id());
        }
    }
}
