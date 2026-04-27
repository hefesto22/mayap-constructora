<?php

declare(strict_types=1);

namespace App\Filament\Resources\Zonas\Pages;

use App\Filament\Concerns\NotificaResultadoClonado;
use App\Filament\Resources\Zonas\ZonaResource;
use App\Models\Zona;
use App\Services\Catalogos\ClonarItemsEntreZonas;
use Filament\Resources\Pages\CreateRecord;

class CreateZona extends CreateRecord
{
    use NotificaResultadoClonado;

    protected static string $resource = ZonaResource::class;

    /**
     * ID de la zona origen seleccionada en el Tab "Inicialización".
     * Se captura antes de persistir y se usa después de crear la zona
     * para clonar items vía ClonarItemsEntreZonas.
     */
    public ?int $zonaOrigenId = null;

    /**
     * Intercepta el form data antes de la persistencia para extraer
     * `zona_origen_id` (campo virtual del Tab "Inicialización") sin
     * que llegue al modelo Zona — esa columna no existe en la tabla.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (isset($data['zona_origen_id']) && $data['zona_origen_id'] !== null && $data['zona_origen_id'] !== '') {
            $this->zonaOrigenId = (int) $data['zona_origen_id'];
        }

        unset($data['zona_origen_id']);

        return $data;
    }

    /**
     * Si el usuario eligió clonar desde otra zona, ejecuta el service
     * y delega la notificación al trait NotificaResultadoClonado.
     */
    protected function afterCreate(): void
    {
        if ($this->zonaOrigenId === null) {
            return;
        }

        $origen = Zona::find($this->zonaOrigenId);

        if ($origen === null) {
            return;
        }

        /** @var Zona $destino */
        $destino = $this->record;

        $resultado = app(ClonarItemsEntreZonas::class)->ejecutar(
            origen: $origen,
            destino: $destino,
            saltarDuplicados: true,
        );

        $this->notificarResultadoClonado($origen, $destino, $resultado);
    }
}
