<?php

declare(strict_types=1);

namespace App\Filament\Resources\GastosReparacion\Pages;

use App\Filament\Resources\GastosReparacion\GastoMantenimientoResource;
use App\Models\MantenimientoMaquina;
use Filament\Resources\Pages\CreateRecord;
use Override;

class CreateGastoMantenimiento extends CreateRecord
{
    protected static string $resource = GastoMantenimientoResource::class;

    /**
     * La máquina y la obra NO se preguntan: salen de la reparación
     * elegida (2026-09-05). Preguntarlas invitaría a que no coincidieran.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $mantenimiento = MantenimientoMaquina::find((int) ($data['mantenimiento_id'] ?? 0));

        $data['maquina_id'] = $mantenimiento?->maquina_id;
        $data['proyecto_id'] = $mantenimiento?->proyecto_id;
        $data['registrado_por'] = auth()->id();

        // Lo que registra quien compra nace respaldado.
        $data['conciliado_at'] = now();
        $data['conciliado_por'] = auth()->id();

        if ($data['proyecto_id'] === null) {
            $data['cargar_a_proyecto'] = false;
        }

        if (! ($data['cargar_a_proyecto'] ?? false)) {
            $data['tipo_cargo_obra'] = null;
            $data['monto_obra'] = null;
        }

        return $data;
    }
}
