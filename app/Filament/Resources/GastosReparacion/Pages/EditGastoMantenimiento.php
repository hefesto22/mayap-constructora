<?php

declare(strict_types=1);

namespace App\Filament\Resources\GastosReparacion\Pages;

use App\Filament\Resources\GastosReparacion\GastoMantenimientoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditGastoMantenimiento extends EditRecord
{
    protected static string $resource = GastoMantenimientoResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Ligar la factura ES respaldarlo: no hace falta un segundo clic.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! ($data['cargar_a_proyecto'] ?? false)) {
            $data['tipo_cargo_obra'] = null;
            $data['monto_obra'] = null;
        }

        if (filled($data['compra_id'] ?? null) && $this->record->conciliado_at === null) {
            $data['conciliado_at'] = now();
            $data['conciliado_por'] = auth()->id();
        }

        return $data;
    }
}
