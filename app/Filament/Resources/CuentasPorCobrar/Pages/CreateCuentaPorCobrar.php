<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Pages;

use App\Filament\Resources\CuentasPorCobrar\CuentaPorCobrarResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCuentaPorCobrar extends CreateRecord
{
    protected static string $resource = CuentaPorCobrarResource::class;

    /**
     * El saldo inicia igual al monto: aún no se ha cobrado nada.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['saldo'] = $data['monto_original'];

        return $data;
    }
}
