<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Actions;

use App\Exceptions\WhatsApp\WhatsAppException;
use App\Models\Proyecto;
use App\Services\Reportes\CotizacionRentaService;
use App\Services\WhatsApp\EnviarWhatsAppService;
use App\Support\Permisos;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * "Enviar cotización por WhatsApp" — el envío DIRECTO (decisión
 * Mauricio 2026-07-19): el sistema genera la imagen de la cotización
 * y se la manda al cliente vía Evolution API, sin abrir pestañas ni
 * adjuntar a mano.
 *
 * Solo aparece con WHATSAPP_ENABLED=true y el cliente con teléfono;
 * el flujo wa.me sigue disponible como respaldo manual.
 *
 * load() y NO loadMissing() para el cliente: la página lo trae cargado
 * con pocas columnas (sin teléfono) y loadMissing no lo recarga.
 */
final class AccionEnviarCotizacionWhatsApp
{
    public static function make(): Action
    {
        return Action::make('enviar_cotizacion_whatsapp')
            ->label('Enviar cotización por WhatsApp')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->visible(function (Proyecto $record): bool {
                if (! $record->esRenta() || ! app(EnviarWhatsAppService::class)->habilitado()) {
                    return false;
                }

                $record->load('cliente:id,nombre,telefono');
                $cliente = $record->cliente;

                return $cliente !== null
                    && EnviarWhatsAppService::normalizarTelefono($cliente->telefono) !== null
                    && (auth()->user()?->can(Permisos::DESCARGAR_PDF_COMPOSICION_PROYECTO) ?? false);
            })
            ->requiresConfirmation()
            ->modalHeading('Enviar cotización por WhatsApp')
            ->modalDescription(function (Proyecto $record): string {
                $record->load('cliente:id,nombre,telefono');
                $cliente = $record->cliente;

                if ($cliente === null) {
                    return 'El proyecto no tiene cliente con teléfono registrado.';
                }

                $numero = EnviarWhatsAppService::normalizarTelefono($cliente->telefono) ?? '—';

                return "Se genera la imagen de {$record->codigo} y se envía al "
                    ."{$numero} ({$cliente->nombre}) desde el número de la empresa, "
                    .'con el mensaje de cortesía y el total. El envío queda en la bitácora.';
            })
            ->modalSubmitActionLabel('Enviar ahora')
            ->action(function (Proyecto $record): void {
                $cotizacion = app(CotizacionRentaService::class);
                $whatsapp = app(EnviarWhatsAppService::class);

                $record->load('cliente:id,nombre,telefono,condicion_pago,dias_credito');
                $cliente = $record->cliente;

                if ($cliente === null) {
                    Notification::make()
                        ->danger()
                        ->title('Sin cliente')
                        ->body('El proyecto no tiene cliente registrado.')
                        ->send();

                    return;
                }

                $userId = auth()->id();
                $userId = is_numeric($userId) ? (int) $userId : null;

                try {
                    $whatsapp->enviarImagen(
                        telefono: (string) $cliente->telefono,
                        rutaAbsoluta: $cotizacion->generarImagen($record),
                        caption: $cotizacion->mensajeCotizacion($record),
                        userId: $userId,
                    );
                } catch (WhatsAppException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo enviar')
                        ->body($e->getMessage())
                        ->send();

                    return;
                } catch (Throwable $e) {
                    report($e);

                    Notification::make()
                        ->danger()
                        ->title('No se pudo generar la cotización')
                        ->body('El detalle quedó registrado en el log.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Cotización enviada')
                    ->body("Al cliente {$cliente->nombre} le llegó la imagen de {$record->codigo} por WhatsApp.")
                    ->send();
            });
    }
}
