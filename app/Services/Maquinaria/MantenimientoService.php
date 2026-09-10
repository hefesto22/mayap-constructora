<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\DestinoAgendaFutura;
use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoMaquina;
use App\Enums\LugarReparacion;
use App\Enums\PrioridadMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Filament\Resources\Mantenimientos\MantenimientoMaquinaResource;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\BitacoraMantenimiento;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions\Action as NotificationAction;
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
        ?LugarReparacion $lugar = null,
        ?string $necesita = null,
        ?int $proyectoId = null,
        ?PrioridadMantenimiento $prioridad = null,
    ): MantenimientoMaquina {
        return DB::transaction(function () use ($maquina, $motivo, $sustituta, $fecha, $notas, $destinoAgenda, $lugar, $necesita, $proyectoId, $prioridad): MantenimientoMaquina {
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

            // ¿La máquina se mueve? (decisión Mauricio 2026-09-05). Si la
            // reparación es EN SITIO, la obra CONSERVA la máquina: no se
            // corta la asignación, la agenda queda en pie y no hay
            // sustituta que buscar — lo único que falta es que le lleven
            // lo que necesita.
            $enSitio = $lugar?->enSitio() ?? false;

            // Qué pasa con los agendados FUTUROS lo decide quien reporta
            // (decisión Mauricio 2026-07-22). Sin decisión explícita: con
            // sustituta se transfieren, sin ella se cancelan (lo clásico).
            $destino = $enSitio
                ? DestinoAgendaFutura::ReparacionHoy
                : ($destinoAgenda
                    ?? ($sustituta instanceof Maquina ? DestinoAgendaFutura::Sustituta : DestinoAgendaFutura::Cancelar));

            if ($enSitio) {
                $sustituta = null;
            }

            if ($destino === DestinoAgendaFutura::Sustituta && ! $sustituta instanceof Maquina) {
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

            $obraId = $proyectoId ?? $asignacionActiva?->proyecto_id;

            // EN SITIO la asignación NO se corta: la máquina sigue en esa
            // obra, solo que detenida hasta que llegue el repuesto.
            if ($asignacionActiva !== null && ! $enSitio) {
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

            if ($sustituta instanceof Maquina) {
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
                'proyecto_id'              => $obraId,
                'en_sitio'                 => $enSitio,
                'necesita'                 => $necesita,
                'asignacion_finalizada_id' => $enSitio ? null : $asignacionActiva?->id,
                'asignacion_sustituta_id'  => $asignacionSustituta?->id,
                'estado'                   => EstadoMantenimiento::EnProceso,
                // Quien reporta dice si urge o puede esperar: es el que
                // está viendo la máquina parada (2026-09-05).
                'prioridad' => ($prioridad ?? PrioridadMantenimiento::Normal)->value,
                'notas'     => $notas,
            ]);

            // EN SITIO el aviso no es "se fue al taller": es un PEDIDO con
            // dirección — qué llevar y a qué obra. Lo reciben maquinaria y
            // recepción, que son quienes lo despachan.
            if ($enSitio) {
                $this->notificarReparacionEnSitio($mantenimiento, $maquinaBloqueada, $necesita);
            }

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
     * "Ver la reparación": la campanita sin un botón que lleve al
     * expediente obliga a buscarlo a mano en el listado (Mauricio
     * 2026-09-05).
     */
    private function botonVerReparacion(MantenimientoMaquina $mantenimiento): NotificationAction
    {
        return NotificationAction::make('ver_reparacion')
            ->label('Ver la reparación')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(MantenimientoMaquinaResource::getUrl('view', ['record' => $mantenimiento->getKey()]))
            ->button();
    }

    /**
     * Campanita de REPARACIÓN EN SITIO: la máquina no se movió, y lo que
     * hace falta es que alguien le lleve algo. Va a maquinaria y a
     * recepción — recepción es quien despacha — con el pedido y la obra a
     * la que hay que llevarlo (decisión Mauricio 2026-09-05).
     *
     * notifyNow (síncrono): respeta la transacción del caller.
     */
    private function notificarReparacionEnSitio(
        MantenimientoMaquina $mantenimiento,
        Maquina $maquina,
        ?string $necesita,
    ): void {
        $urge = $mantenimiento->prioridad === PrioridadMantenimiento::Urgente;
        $mantenimiento->loadMissing('proyecto:id,nombre');
        $obra = $mantenimiento->proyecto?->nombre;

        $notificacion = Notification::make()
            ->title(($urge ? 'URGENTE · ' : '')."{$maquina->nombre} averiada — se repara EN LA OBRA")
            ->body(
                ($obra !== null ? "Sigue en {$obra}. " : 'La máquina no se movió. ')
                .(filled($necesita)
                    ? "Necesita: {$necesita}. "
                    : 'No se especificó qué necesita — confirma con el encargado antes de despachar. ')
                .($urge
                    ? 'El encargado lo marcó URGENTE: la obra está parada esperándolo.'
                    : 'Puede esperar, pero la máquina no se puede agendar a otra obra mientras tanto.')
            )
            ->icon($urge ? 'heroicon-o-fire' : 'heroicon-o-wrench')
            ->{$urge ? 'danger' : 'warning'}()
            ->actions([$this->botonVerReparacion($mantenimiento)])
            ->persistent();

        // whereHas en vez del scope role(): no explota si el rol aún no
        // existe (DB fresca de tests o seeds parciales).
        User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::MAQUINARIA, Roles::RECEPCION, Roles::GERENCIA]))
            ->where('is_active', true)
            ->get()
            ->unique('id')
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
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
     * NO SE PUDO REPARAR EN LA OBRA — la máquina sale al taller
     * (Mauricio 2026-09-05).
     *
     * Es el segundo tiempo de la reparación en sitio: se intentó, no
     * salió, y recién ahora la máquina deja la obra. Por eso todo lo que
     * NO se hizo al reportar se hace aquí: se corta la asignación, se
     * resuelve la agenda comprometida y queda escrito POR QUÉ no se pudo
     * — que es lo que el taller necesita saber antes de que llegue.
     */
    public function escalarATaller(
        MantenimientoMaquina $mantenimiento,
        string $motivo,
        ?Maquina $sustituta = null,
        ?DestinoAgendaFutura $destinoAgenda = null,
        ?int $userId = null,
    ): MantenimientoMaquina {
        return DB::transaction(function () use ($mantenimiento, $motivo, $sustituta, $destinoAgenda, $userId): MantenimientoMaquina {
            $abierto = MantenimientoMaquina::query()
                ->whereKey($mantenimiento->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $abierto->estado->esEnProceso()) {
                throw MantenimientoInvalidoException::mantenimientoNoEnProceso($abierto->codigo);
            }

            $maquina = Maquina::query()->whereKey($abierto->maquina_id)->lockForUpdate()->firstOrFail();
            $fecha = now()->toDateString();

            $destino = $destinoAgenda
                ?? ($sustituta instanceof Maquina ? DestinoAgendaFutura::Sustituta : DestinoAgendaFutura::Cancelar);

            if ($destino === DestinoAgendaFutura::Sustituta && ! $sustituta instanceof Maquina) {
                throw MantenimientoInvalidoException::faltaSustituta($maquina->codigo);
            }

            if ($destino->quedaEnPie()) {
                $sustituta = null;
            }

            // Ahora SÍ la obra pierde la máquina: se corta la asignación
            // que la reparación en sitio había dejado viva.
            $asignacionActiva = AsignacionMaquina::query()
                ->where('maquina_id', $maquina->id)
                ->activas()
                ->lockForUpdate()
                ->first();

            $obraId = $asignacionActiva->proyecto_id ?? $abierto->proyecto_id;

            if ($asignacionActiva !== null) {
                $asignacionActiva->estado = EstadoAsignacion::Finalizada;
                $asignacionActiva->fecha_fin = Carbon::parse($fecha)->max($asignacionActiva->fecha_inicio);
                $asignacionActiva->save();
            }

            $asignacionSustituta = null;

            if ($sustituta instanceof Maquina) {
                if ($obraId === null) {
                    throw MantenimientoInvalidoException::sinObraParaSustituir($maquina->codigo);
                }

                $asignacionSustituta = $this->asignador->asignar(
                    maquina: $sustituta,
                    proyectoId: $obraId,
                    fechaInicio: $fecha,
                    notas: "Sustituye a {$maquina->codigo}: la reparación en obra no salió y se fue al taller.",
                );
            }

            $abierto->forceFill([
                'en_sitio'                 => false,
                'asignacion_finalizada_id' => $asignacionActiva->id ?? $abierto->asignacion_finalizada_id,
                'asignacion_sustituta_id'  => $asignacionSustituta->id ?? $abierto->asignacion_sustituta_id,
                'notas'                    => trim(($abierto->notas !== null ? $abierto->notas."\n" : '')
                    .'NO SE PUDO REPARAR EN LA OBRA: '.$motivo),
            ])->save();

            BitacoraMantenimiento::create([
                'mantenimiento_maquina_id' => $abierto->id,
                'fase'                     => $abierto->fase,
                'detalle'                  => 'No se pudo reparar en la obra — sale al taller. Motivo: '.$motivo,
                'user_id'                  => $userId,
            ]);

            if ($destino->quedaEnPie()) {
                $enPie = $this->dejarAgendaEnPie($maquina, $fecha, $destino);

                if ($enPie > 0) {
                    $this->notificarAgendaEnPie($maquina, $destino, $enPie);
                }
            } else {
                $agenda = $this->reagendador->resolver($maquina, $fecha, $sustituta);

                if ($agenda['transferidos'] > 0 || $agenda['cancelados'] > 0) {
                    $this->notificarAgendaResuelta($maquina, $agenda);
                }
            }

            $this->notificarSalidaATaller($abierto, $maquina, $motivo);

            return $abierto->refresh();
        });
    }

    /**
     * Campanita del escalado: la máquina que se iba a arreglar en la obra
     * termina yendo al taller, y con el porqué.
     */
    private function notificarSalidaATaller(MantenimientoMaquina $mantenimiento, Maquina $maquina, string $motivo): void
    {
        $notificacion = Notification::make()
            ->title("{$maquina->nombre} sale al taller — no se pudo reparar en la obra")
            ->body("Motivo: {$motivo}")
            ->icon('heroicon-o-wrench-screwdriver')
            ->danger()
            ->actions([$this->botonVerReparacion($mantenimiento)])
            ->persistent();

        User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::MAQUINARIA, Roles::RECEPCION, Roles::GERENCIA]))
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

    /**
     * Devuelve una máquina al parque DESDE el catálogo de Maquinaria —
     * la salida del taller en el mismo lugar donde se ve el problema
     * (decisión Mauricio 2026-08-16). Antes la única puerta vivía en el
     * módulo Mantenimientos y una máquina "En mantenimiento" no tenía
     * ninguna acción que la sacara.
     *
     * Cierra la reparación abierta por la MISMA puerta que la acción
     * Finalizar (misma bitácora, misma regla de fechas). Si el estado
     * quedó HUÉRFANO —en mantenimiento sin expediente abierto— la libera
     * igual y lo deja anotado: ese callejón sin salida dejaba la máquina
     * trabada para siempre.
     *
     * @return MantenimientoMaquina|null El expediente cerrado; null si no había ninguno.
     */
    public function marcarReparada(Maquina $maquina, ?string $fechaFin = null, ?int $userId = null): ?MantenimientoMaquina
    {
        return DB::transaction(function () use ($maquina, $fechaFin, $userId): ?MantenimientoMaquina {
            $maquinaBloqueada = Maquina::query()
                ->whereKey($maquina->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($maquinaBloqueada->estado !== EstadoMaquina::Mantenimiento) {
                throw MantenimientoInvalidoException::maquinaNoEnTaller(
                    $maquinaBloqueada->codigo,
                    $maquinaBloqueada->estado,
                );
            }

            $abierto = MantenimientoMaquina::query()
                ->where('maquina_id', $maquinaBloqueada->id)
                ->where('estado', EstadoMantenimiento::EnProceso->value)
                ->orderByDesc('fecha_inicio')
                ->lockForUpdate()
                ->first();

            if ($abierto !== null) {
                $this->finalizar($abierto, $fechaFin, $userId);

                return $abierto->refresh();
            }

            // Estado huérfano: sin expediente que cerrar, la liberación
            // queda en el registro de actividad (quién y cuándo).
            $maquinaBloqueada->estado = EstadoMaquina::Disponible;
            $maquinaBloqueada->save();

            activity('maquinaria')
                ->performedOn($maquinaBloqueada)
                ->withProperties(['fecha' => $fechaFin ?? now()->toDateString()])
                ->event('liberada_sin_expediente')
                ->log("{$maquinaBloqueada->codigo} volvió al parque sin expediente de reparación abierto");

            return null;
        });
    }
}
