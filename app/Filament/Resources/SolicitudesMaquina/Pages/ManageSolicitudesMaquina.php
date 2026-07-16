<?php

declare(strict_types=1);

namespace App\Filament\Resources\SolicitudesMaquina\Pages;

use App\Enums\EstadoSolicitudMaquina;
use App\Enums\PrioridadSolicitud;
use App\Exceptions\Maquinaria\MaquinariaException;
use App\Filament\Resources\SolicitudesMaquina\SolicitudMaquinaResource;
use App\Models\SolicitudMaquina;
use App\Services\Maquinaria\SolicitarMaquinaService;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;

class ManageSolicitudesMaquina extends ManageRecords
{
    protected static string $resource = SolicitudMaquinaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Solicitar máquina')
                ->modalHeading('Solicitar máquina')
                ->modalDescription('Pide la máquina para tu obra. Si está libre ese día se agenda al instante; si no, maquinaria resuelve la solicitud.')
                ->modalWidth('2xl')
                ->schema(SolicitudMaquinaResource::camposSolicitar())
                ->createAnother(false)
                // El service es la única puerta: crea la solicitud y deja
                // que la agenda decida (Agendada al instante o Pendiente).
                ->using(function (array $data): SolicitudMaquina {
                    $fechas = array_values((array) ($data['fechas'] ?? []));

                    try {
                        return app(SolicitarMaquinaService::class)->crear(
                            proyectoId: (int) $data['proyecto_id'],
                            maquinaId: (int) $data['maquina_id'],
                            fechaDesde: (string) ($fechas[0] ?? today()->toDateString()),
                            horaLlegada: (string) $data['hora_llegada'],
                            fechaHasta: isset($fechas[1]) ? (string) $fechas[1] : null,
                            notas: is_string($data['notas'] ?? null) && trim((string) $data['notas']) !== '' ? (string) $data['notas'] : null,
                            userId: is_numeric(auth()->id()) ? (int) auth()->id() : null,
                            // ToggleButtons con enum entrega el enum ya casteado;
                            // por si llega crudo (string), se normaliza.
                            prioridad: $data['prioridad'] instanceof PrioridadSolicitud
                                ? $data['prioridad']
                                : (PrioridadSolicitud::tryFrom((string) ($data['prioridad'] ?? '')) ?? PrioridadSolicitud::Normal),
                        );
                    } catch (MaquinariaException $e) {
                        // Respaldo de la validación del form (carreras):
                        // aviso claro y el modal se queda abierto.
                        Notification::make()
                            ->title('No se pudo crear la solicitud')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        throw new Halt;
                    }
                })
                ->successNotification(null)
                // El resultado se dice de frente: agendada con día y hora,
                // o pendiente con el porqué.
                ->after(function (SolicitudMaquina $record): void {
                    $record->loadMissing('maquina:id,nombre');

                    if ($record->estado === EstadoSolicitudMaquina::Agendada) {
                        Notification::make()
                            ->title('Solicitud agendada al instante')
                            ->body(
                                "{$record->maquina->nombre} — ".$record->rangoParaEl()
                                .($record->horaLlegada12() !== null ? ', llega '.$record->horaLlegada12() : '')
                                .(str_contains((string) $record->motivo, 'Saltados') ? ". {$record->motivo}" : '')
                            )
                            ->success()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Solicitud registrada — pendiente por resolver')
                        ->body(
                            'La máquina no estaba disponible ese día'
                            .($record->motivo !== null ? ": {$record->motivo}" : '.')
                            .' El equipo de maquinaria la resolverá y recibirás el aviso.'
                        )
                        ->warning()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
