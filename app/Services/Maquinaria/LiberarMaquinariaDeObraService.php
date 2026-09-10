<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoAsignacion;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\Proyecto;
use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Notification;

/**
 * Una obra que se cierra (finalizada o cancelada) suelta su maquinaria.
 *
 * Antes el proyecto cambiaba de estado y NADIE tocaba las asignaciones:
 * la máquina quedaba "Asignada" a una obra que ya no existe, sin ninguna
 * pantalla que la liberara — y el rol que cierra la obra (gerencia) ni
 * siquiera ve el módulo de Asignaciones (corregido 2026-08-16).
 *
 * Decisión Mauricio 2026-08-16: se libera TODO y se avisa. Las
 * asignaciones activas se cierran con la fecha de cierre de la obra y
 * los días agendados de ahí en adelante se cancelan — igual que la
 * avería, el evento que rompe el compromiso lo resuelve solo y deja
 * constancia.
 *
 * Corre DENTRO de la transacción de quien cierra la obra: si el cierre
 * se revierte, la maquinaria vuelve a su compromiso.
 */
final readonly class LiberarMaquinariaDeObraService
{
    public function __construct(private AsignarMaquinaService $asignaciones) {}

    /**
     * @return array{liberadas: list<string>, cancelados: int}
     */
    public function liberar(Proyecto $proyecto, string $fechaCierre): array
    {
        $activas = AsignacionMaquina::query()
            ->with('maquina:id,nombre')
            ->where('proyecto_id', $proyecto->id)
            ->where('estado', EstadoAsignacion::Activa->value)
            ->lockForUpdate()
            ->get();

        $liberadas = [];

        foreach ($activas as $asignacion) {
            // Única puerta: misma regla de fechas y mismo retorno a
            // Disponible que finalizar la asignación a mano.
            $this->asignaciones->finalizar($asignacion, $fechaCierre);
            $liberadas[] = $asignacion->maquina->nombre;
        }

        // Los días agendados a esta obra ya no se pueden cumplir. La
        // llegada confirmada es HISTORIA y no se toca (misma regla que
        // ReagendarPorMantenimientoService).
        $agendados = AgendaMaquina::query()
            ->where('proyecto_id', $proyecto->id)
            ->whereDate('fecha', '>=', $fechaCierre)
            ->whereNull('llegada_confirmada_at')
            ->lockForUpdate()
            ->get();

        foreach ($agendados as $agendado) {
            $agendado->delete();
        }

        return ['liberadas' => $liberadas, 'cancelados' => $agendados->count()];
    }

    /**
     * Campanita a maquinaria + gerencia con lo que soltó la obra.
     *
     * notifyNow (síncrono): respeta la transacción del caller — rollback
     * = sin avisos fantasma.
     *
     * @param array{liberadas: list<string>, cancelados: int} $resumen
     */
    public function notificar(Proyecto $proyecto, array $resumen): void
    {
        $cuantas = count($resumen['liberadas']);

        $titulo = "{$proyecto->nombre} cerró: "
            .implode(' · ', array_filter([
                $cuantas > 0 ? "{$cuantas} máquina(s) liberada(s)" : null,
                $resumen['cancelados'] > 0 ? "{$resumen['cancelados']} agendado(s) cancelado(s)" : null,
            ]));

        $notificacion = Notification::make()
            ->title($titulo)
            ->body(($cuantas > 0 ? implode(' · ', array_slice($resumen['liberadas'], 0, 6)).'. ' : '')
                .'Ya están disponibles para otra obra.')
            ->warning()
            ->persistent();

        // whereHas en vez del scope role(): este NO explota si el rol aún
        // no existe (DB fresca de tests o seeds parciales).
        User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::MAQUINARIA, Roles::GERENCIA]))
            ->where('is_active', true)
            ->get()
            ->unique('id')
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }
}
