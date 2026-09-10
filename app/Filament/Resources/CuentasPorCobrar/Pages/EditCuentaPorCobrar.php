<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Pages;

use App\Filament\Resources\CuentasPorCobrar\CuentaPorCobrarResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditCuentaPorCobrar extends EditRecord
{
    protected static string $resource = CuentaPorCobrarResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * La edición solo está disponible mientras la cuenta está pendiente (sin
     * cobros), así que el saldo sigue al monto editado.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['monto_original'])) {
            $data['saldo'] = $data['monto_original'];
        }

        return $data;
    }
}
