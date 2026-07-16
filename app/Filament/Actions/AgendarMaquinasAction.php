<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Filament\Resources\AgendaMaquina\AgendaMaquinaResource;
use App\Services\Maquinaria\AgendarMaquinaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * "Agendar máquinas" en lote — ÚNICA definición de la acción, montada en
 * tres lugares: cabecera del calendario, drag sobre días del calendario
 * (fechas prellenadas vía arguments) y cabecera de la Resource Agenda.
 *
 * Un solo guardado crea máquinas × días; los choques se saltan y se
 * reportan en la notificación (con 30 máquinas, abortar todo por un
 * conflicto sería contraproducente).
 */
final class AgendarMaquinasAction
{
    public static function make(): Action
    {
        return Action::make('agendar')
            ->label('Agendar máquinas')
            ->icon('heroicon-o-calendar-days')
            ->modalHeading('Agendar maquinaria')
            ->modalDescription('Compromete una o varias máquinas a una obra por un rango de días. Las máquinas en taller durante esas fechas aparecen deshabilitadas en el listado.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Agendar')
            ->visible(fn (): bool => auth()->user()?->can('Create:AgendaMaquina') ?? false)
            ->schema(AgendaMaquinaResource::camposAgendar())
            // El drag del calendario manda desde/hasta como arguments; el
            // botón normal cae en los defaults (mañana a las 8:00, un día).
            ->fillForm(function (array $arguments): array {
                $desde = $arguments['desde'] ?? today()->addDay()->toDateString();
                $hasta = $arguments['hasta'] ?? $desde;

                return [
                    'maquina_ids' => [],
                    'proyecto_id' => null,
                    // El drag manda el rango; un click = un solo día.
                    'fechas' => $hasta > $desde ? [$desde, $hasta] : [$desde],
                    // 8:00 AM: hora estándar de llegada (y del aviso
                    // "confirma la llegada").
                    'hora_entrada'     => '08:00',
                    'excluir_domingos' => true,
                    'notas'            => null,
                ];
            })
            ->action(function (array $data): void {
                /** @var list<string> $fechas */
                $fechas = array_values((array) ($data['fechas'] ?? []));
                $desde = $fechas[0] ?? today()->addDay()->toDateString();
                $hasta = $fechas[1] ?? $desde;

                try {
                    $resultado = app(AgendarMaquinaService::class)->agendarLote(
                        maquinaIds: array_values(array_map(intval(...), (array) $data['maquina_ids'])),
                        proyectoId: (int) $data['proyecto_id'],
                        desde: $desde,
                        hasta: $hasta,
                        excluirDomingos: (bool) ($data['excluir_domingos'] ?? true),
                        notas: $data['notas'] ?? null,
                        userId: is_numeric(auth()->id()) ? (int) auth()->id() : null,
                        horaEntrada: Carbon::parse((string) ($data['hora_entrada'] ?? '08:00'))->format('H:i:s'),
                    );
                } catch (AgendaInvalidaException $e) {
                    Notification::make()
                        ->title('No se pudo agendar')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                self::notificarResultado($resultado);
            });
    }

    /**
     * @param array{creados: int, saltados: list<string>} $resultado
     */
    private static function notificarResultado(array $resultado): void
    {
        if ($resultado['creados'] === 0) {
            Notification::make()
                ->title('Nada agendado — todos los días chocaron')
                ->body(implode("\n", array_slice($resultado['saltados'], 0, 3)))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $notificacion = Notification::make()
            ->title("{$resultado['creados']} agendado(s)")
            ->success();

        if ($resultado['saltados'] !== []) {
            $notificacion
                ->title("{$resultado['creados']} agendado(s) · ".count($resultado['saltados']).' saltado(s)')
                ->body(implode("\n", array_slice($resultado['saltados'], 0, 3)))
                ->warning()
                ->persistent();
        }

        $notificacion->send();
    }
}
