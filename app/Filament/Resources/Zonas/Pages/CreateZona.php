<?php

declare(strict_types=1);

namespace App\Filament\Resources\Zonas\Pages;

use App\Filament\Concerns\NotificaResultadoClonado;
use App\Filament\Resources\Zonas\ZonaResource;
use App\Models\Ficha;
use App\Models\Zona;
use App\Services\Catalogos\ClonarItemsEntreZonas;
use App\Services\Fichas\DuplicarFichaAOtraZona;
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
     * Si el usuario pidió copiar también las fichas APU de la zona origen.
     * Solo aplica cuando se hereda una base de precios (zonaOrigenId != null).
     */
    public bool $copiarFichas = true;

    /**
     * Intercepta el form data antes de la persistencia para extraer
     * `zona_origen_id` y `copiar_fichas` (campos virtuales del Tab
     * "Inicialización") sin que lleguen al modelo Zona — esas columnas
     * no existen en la tabla.
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

        $this->copiarFichas = (bool) ($data['copiar_fichas'] ?? true);

        unset($data['zona_origen_id'], $data['copiar_fichas']);

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

        // Copiar las fichas APU de la zona origen solo si el usuario lo pidió
        // (toggle "Copiar también las fichas APU"). Usan los items recién
        // clonados (con sus precios), así arrancan con el mismo cálculo
        // recalculado para esta zona. Cada ficha destino es independiente.
        if ($this->copiarFichas) {
            $duplicador = app(DuplicarFichaAOtraZona::class);

            Ficha::query()
                ->where('zona_id', $origen->id)
                ->where('activa', true)
                ->get()
                ->each(fn (Ficha $ficha) => $duplicador->ejecutar($ficha, $destino));
        }

        $this->notificarResultadoClonado($origen, $destino, $resultado);
    }
}
