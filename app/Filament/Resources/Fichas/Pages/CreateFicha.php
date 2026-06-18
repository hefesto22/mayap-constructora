<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fichas\Pages;

use App\Filament\Resources\Fichas\FichaResource;
use App\Models\Ficha;
use App\Services\Fichas\CalcularPrecioFichaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateFicha extends CreateRecord
{
    protected static string $resource = FichaResource::class;

    /**
     * Después de crear la ficha y sus líneas, recalcular el cache de
     * precio inmediatamente. Sin esto, el listado mostraría L 0.00
     * hasta el primer recálculo manual — confuso para el usuario.
     *
     * Recalcular al crear es barato (la ficha recién creada tiene
     * pocas líneas) y garantiza que `subtotal_cache` y
     * `precio_venta_cache` estén consistentes desde el día 0.
     */
    protected function afterCreate(): void
    {
        /** @var Ficha $ficha */
        $ficha = $this->record;

        $resultado = app(CalcularPrecioFichaService::class)
            ->recalcularYPersistir($ficha);

        Notification::make()
            ->success()
            ->title('Ficha creada y calculada')
            ->body("Precio venta: L {$resultado->precioVenta} por {$ficha->unidadMedida->codigo}")
            ->send();
    }
}
