<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use App\Services\Reportes\CostoObraPdfService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ViewProyecto extends ViewRecord
{
    protected static string $resource = ProyectoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf_costos')
                ->label('Descargar PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function (): ?BinaryFileResponse {
                    $record = $this->getRecord();

                    if (! $record instanceof Proyecto) {
                        return null;
                    }

                    try {
                        $ruta = app(CostoObraPdfService::class)->generar($record);
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('No se pudo generar el PDF')
                            ->body('Verifica que Chromium esté disponible en el servidor.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    return response()->download($ruta)->deleteFileAfterSend();
                }),

            EditAction::make(),
        ];
    }
}
