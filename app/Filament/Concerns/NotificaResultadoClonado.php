<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Zona;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Trait compartido para mostrar la notificación tras una operación de
 * clonado de items entre zonas.
 *
 * Se usa en CreateZona (cuando el usuario hereda items al crear) y en
 * EditZona (cuando ejecuta la action "Clonar items desde otra zona").
 * Probablemente también será reutilizado por futuras operaciones tipo
 * BulkAction de clonado o un Job de clonado masivo — extraerlo ahora
 * cumple la regla del 3 anticipadamente (§8.1 del contrato).
 *
 * La notificación incluye un link que filtra la "Base de precios" por
 * la zona destino, conectando la acción con su resultado visible.
 */
trait NotificaResultadoClonado
{
    /**
     * Despacha la notificación adecuada según el resultado del clonado.
     *
     * @param array{clonados: int, omitidos: int} $resultado
     */
    protected function notificarResultadoClonado(Zona $origen, Zona $destino, array $resultado): void
    {
        $clonados = $resultado['clonados'];
        $omitidos = $resultado['omitidos'];

        if ($clonados === 0 && $omitidos === 0) {
            Notification::make()
                ->title("La zona {$origen->nombre} no tiene items activos para clonar")
                ->warning()
                ->send();

            return;
        }

        $mensaje = "Se clonaron {$clonados} items desde {$origen->nombre}.";

        if ($omitidos > 0) {
            $mensaje .= " Se omitieron {$omitidos} porque ya existían en {$destino->nombre}.";
        }

        Notification::make()
            ->title('Clonado completado')
            ->body($mensaje)
            ->success()
            ->actions([
                Action::make('ver_base_precios')
                    ->label("Ver base de precios de {$destino->nombre}")
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(self::urlBaseDePreciosFiltradaPorZona($destino), shouldOpenInNewTab: false)
                    ->button(),
            ])
            ->send();
    }

    /**
     * URL del listado de items con el filtro de zona pre-aplicado para
     * que el usuario aterrice viendo solo los items de la zona destino.
     */
    private static function urlBaseDePreciosFiltradaPorZona(Zona $destino): string
    {
        return ItemResource::getUrl('index', [
            'tableFilters' => [
                'zona_id' => ['value' => $destino->id],
            ],
        ]);
    }
}
