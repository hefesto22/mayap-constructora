<?php

declare(strict_types=1);

namespace App\Services\Requisiciones;

use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Compras\CompraResource;
use App\Filament\Resources\Requisiciones\RequisicionResource;
use App\Models\Compra;
use App\Models\Requisicion;
use App\Models\User;
use App\Services\WhatsApp\EnviarWhatsAppService;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notificaciones de campanita (database) del flujo de requisiciones —
 * ÚNICA fuente de "quién se entera de qué". El sistema avisa al rol que
 * tiene el siguiente paso; nadie persigue a nadie por WhatsApp:
 *
 *  - Solicitada (creación)  → bodegueros: "por autorizar".
 *  - Autorizada             → encargados de la obra: "pedido autorizado".
 *  - EnTransito             → encargados de la obra: "material en camino".
 *  - RequisicionCompra      → recepción: "sin stock, realizar compra".
 *  - Recibida               → bodegueros: "confirmada por la obra, conciliar".
 *  - Discrepancia           → bodegueros + gerencia: "no cuadró".
 *  - Rechazada              → solicitante y encargados de la obra.
 *
 * Despachada y Cerrada no notifican: son pasos del MISMO actor que ya
 * está en pantalla (evitar ruido — una campanita que suena por todo
 * termina ignorada).
 *
 * El actor de la transición nunca se auto-notifica.
 */
final class NotificadorRequisiciones
{
    public function __construct(
        private readonly PresupuestoMaterialesProyectoService $presupuesto,
        private readonly EnviarWhatsAppService $whatsapp,
    ) {}

    // ─── Llegada del material comprado (seguimiento para la obra) ──────
    //
    // Decisión Mauricio 2026-08-07: mientras la requisición está en
    // "Requisición de compra" la obra estaba a ciegas. Estos cuatro avisos
    // cubren la ventana completa: cuándo llega, si la fecha se movió, el
    // día que llega, y el reclamo cuando el proveedor no cumplió.
    //
    // Los tres primeros son ACCIONABLES para el encargado (tiene que estar
    // pendiente de recibir), así que además de la campanita van por
    // WhatsApp: el encargado en Santa Rosa no vive en el sistema. El
    // cuarto es un reclamo interno — campanita nomás, para no quemar el
    // canal con un mensaje diario.

    public function llegadaProgramada(Requisicion $requisicion, Compra $compra, ?int $actorId = null): void
    {
        $detalle = sprintf(
            'La compra %s llega el %s.%s',
            $compra->codigo,
            $compra->fecha_estimada_llegada?->format('d/m/Y') ?? '—',
            $this->contraLaNecesaria($requisicion),
        );

        $destinatarios = $this->encargadosDeLaObra($requisicion);

        $this->enviar(
            destinatarios: $destinatarios,
            requisicion: $requisicion,
            titulo: $requisicion->llegaTarde()
                ? '⚠ Tu material llega TARDE'
                : 'Ya sabemos cuándo llega tu material',
            actorId: $actorId,
            detalle: $detalle,
        );

        $this->porWhatsApp(
            $destinatarios,
            "*{$requisicion->codigo}* · {$requisicion->proyecto->nombre}\n{$detalle}",
            $actorId,
        );
    }

    public function llegadaReprogramada(
        Requisicion $requisicion,
        Compra $compra,
        Carbon $anterior,
        string $motivo,
        ?int $actorId = null,
    ): void {
        $nueva = $compra->fecha_estimada_llegada;
        $seAtrasa = $nueva !== null && $nueva->gt($anterior);

        $detalle = sprintf(
            '%s: %s → %s. Motivo: %s%s',
            $compra->codigo,
            $anterior->format('d/m/Y'),
            $nueva?->format('d/m/Y') ?? '—',
            $motivo,
            $this->contraLaNecesaria($requisicion),
        );

        // El atraso también le llega a gerencia: es quien decide si se
        // reprograma la actividad de la obra.
        $destinatarios = $this->encargadosDeLaObra($requisicion)
            ->when(
                $seAtrasa,
                fn (Collection $usuarios): Collection => $usuarios->merge($this->usuariosConRol(Roles::GERENCIA)),
            );

        $this->enviar(
            destinatarios: $destinatarios,
            requisicion: $requisicion,
            titulo: $seAtrasa ? '⚠ ATRASO en la entrega del proveedor' : 'La entrega se adelantó',
            actorId: $actorId,
            detalle: $detalle,
        );

        $this->porWhatsApp(
            $destinatarios,
            ($seAtrasa ? "*⚠ ATRASO*\n" : "*Se adelantó la entrega*\n")
                ."*{$requisicion->codigo}* · {$requisicion->proyecto->nombre}\n{$detalle}",
            $actorId,
        );
    }

    public function llegaHoy(Requisicion $requisicion, Compra $compra): void
    {
        $detalle = "La compra {$compra->codigo} debería llegar HOY a la obra. ".
            'Al recibirla, verificala contra la factura.';

        $destinatarios = $this->encargadosDeLaObra($requisicion);

        $this->enviar(
            destinatarios: $destinatarios,
            requisicion: $requisicion,
            titulo: 'Hoy llega material a tu obra',
            actorId: null,
            detalle: $detalle,
            urlAccion: self::urlDeLaCompra($compra),
            labelAccion: 'Verificar recepción',
        );

        $this->porWhatsApp(
            $destinatarios,
            "*Hoy llega material a {$requisicion->proyecto->nombre}*\n{$requisicion->codigo} · {$detalle}",
            null,
        );
    }

    /**
     * Venció la fecha prometida y el material sigue sin aparecer. Va a la
     * obra Y a compras: si el proveedor no entregó, alguien tiene que
     * reclamarle.
     */
    public function llegadaVencida(Requisicion $requisicion, Compra $compra): void
    {
        $this->enviar(
            destinatarios: $this->encargadosDeLaObra($requisicion)
                ->merge($this->usuariosConRol(Roles::RECEPCION)),
            requisicion: $requisicion,
            titulo: '⚠ El proveedor no ha entregado',
            actorId: null,
            detalle: sprintf(
                'La compra %s se prometió para el %s y sigue sin recibirse. ¿Entregó el proveedor?',
                $compra->codigo,
                $compra->fecha_estimada_llegada?->format('d/m/Y') ?? '—',
            ),
            urlAccion: self::urlDeLaCompra($compra),
            labelAccion: 'Verificar recepción',
        );
    }

    /**
     * Los avisos que significan "andá a recibir" llevan DIRECTO a la
     * compra, no a la requisición: la verificación contra la factura vive
     * en Compras y es lo único que la obra puede tocar en ese momento.
     * Mandarla a la requisición era dejarla en una pantalla sin acción.
     */
    private static function urlDeLaCompra(Compra $compra): string
    {
        return CompraResource::getUrl('index', ['tableSearch' => $compra->codigo]);
    }

    /**
     * "(la necesitabas el 10/08 — llega 1 día tarde)": la frase que le dice
     * a la obra si tiene que preocuparse o solo estar pendiente antes.
     */
    private function contraLaNecesaria(Requisicion $requisicion): string
    {
        $llegada = $requisicion->fecha_estimada_llegada;

        if ($llegada === null) {
            return '';
        }

        $necesaria = $requisicion->fecha_necesaria;
        // (int): diffInDays devuelve float y sin el cast el ===1 nunca
        // daba true — salía "llega 1 días antes".
        $dias = (int) $necesaria->diffInDays($llegada, absolute: true);
        $plural = $dias === 1 ? '' : 's';

        return match (true) {
            $llegada->gt($necesaria) => sprintf(
                ' ⚠ La necesitabas el %s: llega %d día%s TARDE.',
                $necesaria->format('d/m/Y'),
                $dias,
                $plural,
            ),
            $llegada->lt($necesaria) => sprintf(
                ' La pediste para el %s: llega %d día%s antes.',
                $necesaria->format('d/m/Y'),
                $dias,
                $plural,
            ),
            default => ' Justo el día que la necesitabas.',
        };
    }

    /**
     * WhatsApp best-effort (§ regla de las campanitas: avisar NUNCA debe
     * tumbar la operación que lo disparó).
     *
     * - `afterCommit`: si la transacción del caller se revierte, el mensaje
     *   no sale. Un WhatsApp no se puede "des-enviar".
     * - try/catch: sin Evolution arriba, sin teléfono o con la instancia
     *   caída, la campanita ya cumplió y el flujo sigue.
     *
     * @param Collection<int, User> $destinatarios
     */
    private function porWhatsApp(Collection $destinatarios, string $texto, ?int $actorId): void
    {
        if (! $this->whatsapp->habilitado()) {
            return;
        }

        $telefonos = $destinatarios
            ->unique('id')
            ->reject(fn (User $user): bool => $user->id === $actorId)
            ->pluck('phone')
            ->filter(fn (?string $phone): bool => EnviarWhatsAppService::normalizarTelefono($phone) !== null)
            ->values();

        if ($telefonos->isEmpty()) {
            return;
        }

        $enviar = function () use ($telefonos, $texto, $actorId): void {
            foreach ($telefonos as $telefono) {
                try {
                    $this->whatsapp->enviarTexto((string) $telefono, $texto, $actorId);
                } catch (Throwable $e) {
                    Log::warning('WhatsApp de requisición no enviado: '.$e->getMessage());
                }
            }
        };

        try {
            // Fuera de transacción se ejecuta de una; dentro, al commit.
            DB::afterCommit($enviar);
        } catch (Throwable $e) {
            Log::warning('WhatsApp de requisición no programado: '.$e->getMessage());
        }
    }

    public function nuevaSolicitud(Requisicion $requisicion, ?int $actorId = null): void
    {
        $this->enviar(
            destinatarios: $this->usuariosConRol(Roles::BODEGUERO),
            requisicion: $requisicion,
            titulo: 'Nueva requisición por autorizar',
            actorId: $actorId,
        );

        $this->alertarSiExcedePresupuesto($requisicion, $actorId);
    }

    /**
     * Control presupuestario: si la requisición pide MÁS de lo que el
     * presupuesto de la obra permite (lo asignado menos lo ya solicitado),
     * gerencia y bodegueros reciben la alerta CON el detalle por material
     * — así el autorizador decide con los números enfrente, no a ciegas.
     */
    private function alertarSiExcedePresupuesto(Requisicion $requisicion, ?int $actorId): void
    {
        $requisicion->loadMissing('lineas');

        $excesos = $requisicion->lineas
            ->map(fn ($linea): ?PresupuestoMaterial => $this->presupuesto->paraMaterial(
                $requisicion->proyecto_id,
                $linea->material_id,
            ))
            ->filter(fn (?PresupuestoMaterial $pm): bool => $pm !== null && $pm->excedido())
            ->map(fn (PresupuestoMaterial $pm): string => sprintf(
                '%s: excede en %s %s',
                $pm->materialNombre,
                number_format((float) $pm->exceso(), 2),
                $pm->unidad,
            ))
            ->values();

        if ($excesos->isEmpty()) {
            return;
        }

        $this->enviar(
            destinatarios: $this->usuariosConRol(Roles::GERENCIA, Roles::BODEGUERO),
            requisicion: $requisicion,
            titulo: '⚠ Requisición EXCEDE el presupuesto de la obra',
            actorId: $actorId,
            detalle: $excesos->join(' · '),
        );
    }

    public function transicion(Requisicion $requisicion, EstadoRequisicion $destino, ?int $actorId = null): void
    {
        [$destinatarios, $titulo] = match ($destino) {
            EstadoRequisicion::Autorizada        => [$this->encargadosDeLaObra($requisicion), 'Requisición autorizada'],
            EstadoRequisicion::EnTransito        => [$this->encargadosDeLaObra($requisicion), 'Material en camino a tu obra'],
            EstadoRequisicion::RequisicionCompra => [$this->usuariosConRol(Roles::RECEPCION), 'Requisición sin stock — realizar compra'],
            EstadoRequisicion::Recibida          => [$this->usuariosConRol(Roles::BODEGUERO), 'Entrega confirmada por la obra — conciliar'],
            EstadoRequisicion::Discrepancia      => [$this->usuariosConRol(Roles::BODEGUERO, Roles::GERENCIA), 'Discrepancia en la entrega'],
            EstadoRequisicion::Rechazada         => [$this->solicitanteYEncargados($requisicion), 'Requisición rechazada'],
            default                              => [collect(), ''],
        };

        if ($titulo === '') {
            return;
        }

        $this->enviar($destinatarios, $requisicion, $titulo, $actorId);
    }

    /**
     * @param Collection<int, User> $destinatarios
     */
    private function enviar(Collection $destinatarios, Requisicion $requisicion, string $titulo, ?int $actorId, ?string $detalle = null, ?string $urlAccion = null, ?string $labelAccion = null): void
    {
        $requisicion->loadMissing('proyecto:id,codigo,nombre');

        $cuerpo = "{$requisicion->codigo} · {$requisicion->proyecto->nombre}";

        if ($detalle !== null) {
            $cuerpo .= " — {$detalle}";
        }

        $notificacion = Notification::make()
            ->title($titulo)
            ->body($cuerpo)
            ->icon('heroicon-o-clipboard-document-list')
            ->actions([
                Action::make('ver')
                    ->label($labelAccion ?? 'Ver requisición')
                    ->url($urlAccion ?? RequisicionResource::getUrl('view', ['record' => $requisicion]))
                    ->button(),
            ]);

        // notifyNow (SÍNCRONO): la DatabaseNotification de Filament es
        // ShouldQueue — con QUEUE_CONNECTION=redis y sin worker, las
        // campanitas se quedaban atascadas en la cola. Además, síncrona
        // respeta la transacción del caller: rollback = sin avisos.
        $destinatarios
            ->unique('id')
            ->reject(fn (User $user): bool => $user->id === $actorId)
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }

    /**
     * Busca por relación (NO con el scope `role()` de Spatie, que LANZA
     * RoleDoesNotExist si el rol no está sembrado): notificar es un efecto
     * secundario best-effort — jamás debe tumbar la transición que lo
     * disparó. Sin rol o sin usuarios → colección vacía y la vida sigue.
     *
     * @return Collection<int, User>
     */
    private function usuariosConRol(string ...$roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function encargadosDeLaObra(Requisicion $requisicion): Collection
    {
        $requisicion->loadMissing('proyecto.encargados');

        return $requisicion->proyecto->encargados
            ->where('is_active', true)
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function solicitanteYEncargados(Requisicion $requisicion): Collection
    {
        $requisicion->loadMissing('solicitante');

        return $this->encargadosDeLaObra($requisicion)
            ->when(
                $requisicion->solicitante !== null,
                fn (Collection $usuarios): Collection => $usuarios->push($requisicion->solicitante),
            );
    }
}
