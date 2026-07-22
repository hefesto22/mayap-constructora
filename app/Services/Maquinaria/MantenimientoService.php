<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\DestinoAgendaFutura;
use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\BitacoraMantenimiento;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestiona las averías y reparaciones de las máquinas.
 *
 * Al enviar una máquina a mantenimiento: si estaba trabajando, finaliza su
 * asignación (la obra la pierde) y la deja en estado Mantenimiento. Si se
 * indica una máquina sustituta, la asigna a la misma obra y registra la
 * sustitución, dejando trazable qué reemplazó a qué. Todo bajo transacción.
 */
final readonly class MantenimientoService
{
    /** La marca en las notas de un agendado que cubrirá una renta externa. */
    private const string NOTA_RENTA_EXTERNA = 'SE CUBRE CON RENTA EXTERNA';

    public function __construct(
        private AsignarMaquinaService $asignador,
        private ReagendarPorMantenimientoService $reagendador,
    ) {}

    /**
     * Envía una máquina a mantenimiento. Finaliza su asignación activa (si la
     * hay) y, opcionalmente, asigna una máquina sustituta a la misma obra.
     */
    public function enviarAMantenimiento(
        Maquina $maquina,
        string $motivo,
        ?Maquina $sustituta = null,
        ?string $fecha = null,
        ?string $notas = null,
        ?DestinoAgendaFutura $destinoAgenda = null,
    ): MantenimientoMaquina {
        return DB::transaction(function () use ($maquina, $motivo, $sustituta, $fecha, $notas, $destinoAgenda): MantenimientoMaquina {
            $maquinaBloqueada = Maquina::query()
                ->whereKey($maquina->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Solo se manda a mantenimiento una máquina operativa.
            if (in_array($maquinaBloqueada->estado, [EstadoMaquina::Mantenimiento, EstadoMaquina::Baja], strict: true)) {
                throw MantenimientoInvalidoException::maquinaNoOperativa(
                    $maquinaBloqueada->codigo,
                    $maquinaBloqueada->estado,
                );
            }

            $fechaInicio = $fecha ?? now()->toDateString();

            // Qué pasa con los agendados FUTUROS lo decide quien reporta
            // (decisión Mauricio 2026-07-22). Sin decisión explícita: con
            // sustituta se transfieren, sin ella se cancelan (lo clásico).
            $destino = $destinoAgenda
                ?? ($sustituta !== null ? DestinoAgendaFutura::Sustituta : DestinoAgendaFutura::Cancelar);

            if ($destino === DestinoAgendaFutura::Sustituta && $sustituta === null) {
                throw MantenimientoInvalidoException::faltaSustituta($maquinaBloqueada->codigo);
            }

            // Con la agenda EN PIE no hay a quién heredarla: la sustituta
            // solo aplica cuando la agenda se transfiere.
            if ($destino->quedaEnPie()) {
                $sustituta = null;
            }

            // Corta la asignación activa (si trabajaba al averiarse).
            $asignacionActiva = AsignacionMaquina::query()
                ->where('maquina_id', $maquinaBloqueada->id)
                ->activas()
                ->lockForUpdate()
                ->first();

            $obraId = $asignacionActiva?->proyecto_id;

            if ($asignacionActiva !== null) {
                // Una asignación nunca termina antes de empezar: si la avería
                // es anterior a su inicio, se cierra en su fecha de inicio.
                $fechaFinAsignacion = Carbon::parse($fechaInicio)->max($asignacionActiva->fecha_inicio);

                $asignacionActiva->estado = EstadoAsignacion::Finalizada;
                $asignacionActiva->fecha_fin = $fechaFinAsignacion;
                $asignacionActiva->save();
            }

            // La máquina queda fuera de servicio.
            $maquinaBloqueada->estado = EstadoMaquina::Mantenimiento;
            $maquinaBloqueada->save();

            // Sustitución: requiere conocer la obra (asignación activa previa).
            $asignacionSustituta = null;

            if ($sustituta !== null) {
                if ($obraId === null) {
                    throw MantenimientoInvalidoException::sinObraParaSustituir($maquinaBloqueada->codigo);
                }

                $asignacionSustituta = $this->asignador->asignar(
                    maquina: $sustituta,
                    proyectoId: $obraId,
                    fechaInicio: $fechaInicio,
                    notas: "Sustituye a {$maquinaBloqueada->codigo} por mantenimiento.",
                );
            }

            $mantenimiento = MantenimientoMaquina::create([
                'maquina_id'               => $maquinaBloqueada->id,
                'fecha_inicio'             => $fechaInicio,
                'motivo'                   => $motivo,
                'asignacion_finalizada_id' => $asignacionActiva?->id,
                'asignacion_sustituta_id'  => $asignacionSustituta?->id,
                'estado'                   => EstadoMantenimiento::EnProceso,
                'notas'                    => $notas,
            ]);

            if ($destino->quedaEnPie()) {
                // EMERGENCIA: la agenda queda EN PIE — la reparación sale
                // hoy mismo, o una renta externa cubrirá los días.
                $enPie = $this->dejarAgendaEnPie($maquinaBloqueada, $fechaInicio, $destino);

                if ($enPie > 0) {
                    $this->notificarAgendaEnPie($maquinaBloqueada, $destino, $enPie);
                }
            } else {
                // Los agendados FUTUROS quedan imposibles: transferirlos a
                // la sustituta o cancelarlos, con aviso a quien gestiona
                // el parque.
                $agenda = $this->reagendador->resolver($maquinaBloqueada, $fechaInicio, $sustituta);

                if ($agenda['transferidos'] > 0 || $agenda['cancelados'] > 0) {
                    $this->notificarAgendaResuelta($maquinaBloqueada, $agenda);
                }
            }

            return $mantenimiento;
        });
    }

    /**
     * Campanita a maquinaria + gerencia con el destino de cada agendado
     * (transferido a la sustituta o cancelado para reagendar/alquilar).
     *
     * notifyNow (síncrono): respeta la transacción del caller — rollback
     * = sin avisos fantasma.
     *
     * @param array{transferidos: int, cancelados: int, detalle: list<string>} $agenda
     */
    private function notificarAgendaResuelta(Maquina $maquina, array $agenda): void
    {
        $titulo = "{$maquina->nombre} a mantenimiento: "
            .implode(' · ', array_filter([
                $agenda['transferidos'] > 0 ? "{$agenda['transferidos']} agendado(s) transferido(s)" : null,
                $agenda['cancelados'] > 0 ? "{$agenda['cancelados']} cancelado(s)" : null,
            ]));

        $notificacion = Notification::make()
            ->title($titulo)
            ->body(implode("\n", array_slice($agenda['detalle'], 0, 6))
                .($agenda['cancelados'] > 0 ? "\nReagenda al salir del taller o gestiona un alquiler." : ''))
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

    /**
     * La agenda futura queda EN PIE (solo el PLAN: agendados sin llegada
     * confirmada, de la fecha de la avería en adelante). Con renta
     * externa, cada día queda anotado para que nadie lo tome por olvido.
     *
     * @return int Cuántos agendados quedaron en pie.
     */
    private function dejarAgendaEnPie(Maquina $maquina, string $desdeFecha, DestinoAgendaFutura $destino): int
    {
        $agendados = AgendaMaquina::query()
            ->where('maquina_id', $maquina->id)
            ->whereDate('fecha', '>=', $desdeFecha)
            ->whereNull('llegada_confirmada_at')
            ->lockForUpdate()
            ->get();

        if ($destino === DestinoAgendaFutura::RentaExterna) {
            foreach ($agendados as $agendado) {
                $agendado->update([
                    'notas' => Str::limit(
                        $agendado->notas === null
                            ? self::NOTA_RENTA_EXTERNA
                            : "{$agendado->notas} · ".self::NOTA_RENTA_EXTERNA,
                        255,
                        '',
                    ),
                ]);
            }
        }

        return $agendados->count();
    }

    /**
     * Campanita a maquinaria + gerencia: la agenda quedó EN PIE y hay un
     * plan que ejecutar (esperar la reparación de hoy, o salir a rentar).
     *
     * notifyNow (síncrono): respeta la transacción del caller — rollback
     * = sin avisos fantasma.
     */
    private function notificarAgendaEnPie(Maquina $maquina, DestinoAgendaFutura $destino, int $enPie): void
    {
        $notificacion = Notification::make()
            ->title("{$maquina->nombre} a mantenimiento: {$enPie} agendado(s) EN PIE")
            ->body($destino === DestinoAgendaFutura::RentaExterna
                ? 'Se cubrirá con RENTA EXTERNA — gestionar el alquiler para que la obra no pare.'
                : 'La reparación está prevista para HOY mismo. Si no sale del taller, decide: sustituta, renta externa o cancelar.')
            ->warning()
            ->persistent();

        User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::MAQUINARIA, Roles::GERENCIA]))
            ->where('is_active', true)
            ->get()
            ->unique('id')
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }

    /**
     * Finaliza el mantenimiento: la máquina vuelve a estar disponible y
     * la bitácora recibe la última entrada (cierre con fecha y hora).
     */
    public function finalizar(MantenimientoMaquina $mantenimiento, ?string $fechaFin = null, ?int $userId = null): void
    {
        DB::transaction(function () use ($mantenimiento, $fechaFin, $userId): void {
            $mantenimientoBloqueado = MantenimientoMaquina::query()
                ->whereKey($mantenimiento->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $mantenimientoBloqueado->estado->esEnProceso()) {
                throw MantenimientoInvalidoException::mantenimientoNoEnProceso($mantenimientoBloqueado->codigo);
            }

            // Un mantenimiento nunca termina antes de empezar.
            $fecha = $fechaFin !== null ? Carbon::parse($fechaFin) : now();

            $mantenimientoBloqueado->estado = EstadoMantenimiento::Finalizado;
            $mantenimientoBloqueado->fecha_fin = $fecha->max($mantenimientoBloqueado->fecha_inicio);
            $mantenimientoBloqueado->save();

            // Cierre en el historial: en qué fase estaba y cuándo terminó.
            BitacoraMantenimiento::create([
                'mantenimiento_maquina_id' => $mantenimientoBloqueado->id,
                'fase'                     => $mantenimientoBloqueado->fase,
                'detalle'                  => 'Mantenimiento finalizado — la máquina volvió a estar disponible.',
                'user_id'                  => $userId,
            ]);

            $maquina = Maquina::query()
                ->whereKey($mantenimientoBloqueado->maquina_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($maquina->estado === EstadoMaquina::Mantenimiento) {
                $maquina->estado = EstadoMaquina::Disponible;
                $maquina->save();
            }
        });
    }
}
