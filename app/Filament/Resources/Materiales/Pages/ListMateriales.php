<?php

declare(strict_types=1);

namespace App\Filament\Resources\Materiales\Pages;

use App\Filament\Resources\Materiales\MaterialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de materiales — SOLO categoría materiales (decisión Mauricio
 * 2026-07-19): herramienta y equipo tiene su propia sección en el grupo
 * Maquinaria. Sin tabs: aquí ya no hay nada que separar.
 */
class ListMateriales extends ListRecords
{
    protected static string $resource = MaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
