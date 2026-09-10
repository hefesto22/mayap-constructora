<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Filament\Resources\Compras\CompraResource;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\User;
use App\Services\Inventario\Ubicacion;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Campanitas del flujo de recepción de compras (G2) — quién se entera de
 * qué material viene en camino y de cómo terminó la verificación:
 *
 *  - PorRecibir → bodegueros (porción a bodega, con el detalle de lo
 *    esperado) y encargados de la obra (porción directa a obra).
 *  - Verificada con DIFERENCIAS → recepción + gerencia (reclamo al
 *    proveedor pendiente).
 *  - Verificada completa (todo cuadró) → recepción (cierre del ciclo).
 *  - Pedido que debería llegar HOY (compras libres con fecha estimada)
 *    → recepción + gerencia.
 *
 * El actor nunca se auto-notifica. Best-effort: sin roles sembrados no
 * truena — la compra sigue su curso.
 */
final class NotificadorCompras
{
    public function porRecibir(Compra $compra, ?int $actorId = null): void
    {
        $compra->loadMissing('lineas.material:id,nombre', 'proveedor:id,nombre');

        [$lineasBodega, $lineasObra] = $compra->lineas->partition(
            fn (CompraLinea $linea): bool => $this->destinoDeLinea($compra, $linea)->esBodega(),
        );

        if ($lineasBodega->isNotEmpty()) {
            $this->enviar(
                destinatarios: $this->usuariosConRol(Roles::BODEGUERO),
                compra: $compra,
                titulo: 'Compra en camino a bodega — verificar al llegar',
                detalle: $this->resumenLineas($lineasBodega),
                actorId: $actorId,
            );
        }

        if ($lineasObra->isNotEmpty()) {
            $this->enviar(
                destinatarios: $this->encargadosDeObras($lineasObra, $compra),
                compra: $compra,
                titulo: 'Material comprado en camino a tu obra — verificar al llegar',
                detalle: $this->resumenLineas($lineasObra),
                actorId: $actorId,
            );
        }
    }

    /**
     * Compra registrada SIN detalle (Mauricio 2026-09-05, extendida a
     * obra el 2026-09-10): quien recibe es quien va a escribir qué llegó,
     * así que el aviso no es "verificá esto" sino "cuando llegue, anotá
     * qué trajeron".
     */
    public function esperandoCaptura(Compra $compra, ?int $actorId = null): void
    {
        $compra->loadMissing('proveedor:id,nombre');

        $estimada = $compra->fecha_estimada_llegada;

        // Le suena a QUIEN RECIBE, que es quien va a escribir el detalle
        // (Mauricio 2026-09-10): el bodeguero si entra a bodega, el
        // encargado de ESA obra si el camión va directo al sitio.
        $directa = $compra->esDirectaAObra();

        $this->enviar(
            destinatarios: $directa
                ? $this->encargadosDeObra((int) $compra->proyecto_id)
                : $this->usuariosConRol(Roles::BODEGUERO),
            compra: $compra,
            titulo: $directa
                ? 'Va directo a la obra — falta anotar qué bajaron'
                : 'Factura registrada — falta anotar qué llegó a bodega',
            detalle: 'Factura por L. '.number_format((float) $compra->totalDeclarado(), 2)
                .'. Al recibir, anota material por material lo que trajeron: hasta que lo capturado cuadre '
                .'con ese total, la mercadería no entra al inventario.'
                .($estimada !== null ? ' Llegada estimada: '.$estimada->format('d/m/Y').'.' : ''),
            actorId: $actorId,
        );
    }

    /**
     * Pedido LIBRE registrado (taller/equipo/oficina): aquí no hay
     * bodeguero que cuente bultos — se avisa a la oficina que el pedido
     * quedó en camino, con su fecha estimada si la hay.
     */
    public function pedidoLibreRegistrado(Compra $compra, ?int $actorId = null): void
    {
        $compra->loadMissing('lineas', 'proveedor:id,nombre');

        $estimada = $compra->fecha_estimada_llegada;

        $this->enviar(
            destinatarios: $this->usuariosConRol(Roles::RECEPCION, Roles::GERENCIA),
            compra: $compra,
            titulo: 'Pedido registrado — en espera de llegada',
            detalle: $this->resumenLineas($compra->lineas)
                .($estimada !== null ? ' — llegada estimada: '.$estimada->format('d/m/Y') : ''),
            actorId: $actorId,
        );
    }

    /**
     * "El pedido debería estar llegando" — el día de la fecha estimada
     * (o al detectar que ya pasó sin recibirse).
     */
    public function llegadaEstimada(Compra $compra): void
    {
        $compra->loadMissing('proveedor:id,nombre');

        $estimada = $compra->fecha_estimada_llegada;
        $cuando = $estimada !== null && $estimada->isBefore(today())
            ? 'debería haber llegado el '.$estimada->format('d/m/Y')
            : 'debería llegar HOY';

        $this->enviar(
            destinatarios: $this->usuariosConRol(Roles::RECEPCION, Roles::GERENCIA),
            compra: $compra,
            titulo: 'Pedido por llegar',
            detalle: "el pedido {$cuando}. Al recibirlo, usa \"Marcar recibida\".",
            actorId: null,
        );
    }

    public function verificadaConDiferencias(Compra $compra, ?int $actorId = null): void
    {
        $this->enviar(
            destinatarios: $this->usuariosConRol(Roles::RECEPCION, Roles::GERENCIA),
            compra: $compra,
            titulo: 'Recepción con DIFERENCIAS — reclamo al proveedor',
            detalle: $compra->lineas
                ->filter(fn (CompraLinea $l): bool => $l->tieneDiferencia())
                ->map(fn (CompraLinea $l): string => "{$l->nombreLinea()}: facturado {$l->cantidad}, recibido {$l->cantidad_recibida}")
                ->implode(' · '),
            actorId: $actorId,
        );
    }

    public function verificadaCompleta(Compra $compra, ?int $actorId = null): void
    {
        $this->enviar(
            destinatarios: $this->usuariosConRol(Roles::RECEPCION),
            compra: $compra,
            titulo: 'Recepción verificada — todo llegó completo',
            detalle: null,
            actorId: $actorId,
        );
    }

    /**
     * @param Collection<int, User> $destinatarios
     */
    private function enviar(Collection $destinatarios, Compra $compra, string $titulo, ?string $detalle, ?int $actorId): void
    {
        $cuerpo = "{$compra->codigo} · {$compra->proveedor->nombre}";

        if ($detalle !== null && $detalle !== '') {
            $cuerpo .= " — {$detalle}";
        }

        $notificacion = Notification::make()
            ->title($titulo)
            ->body($cuerpo)
            ->icon('heroicon-o-truck')
            ->actions([
                Action::make('ver')
                    ->label('Ver compra')
                    ->url(CompraResource::getUrl('index'))
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
     * "100 CEMENTO · 3 ARENA · 150 VAR#4" — el reporte de lo esperado.
     * En líneas libres usa la descripción escrita a mano.
     *
     * @param Collection<int, CompraLinea> $lineas
     */
    private function resumenLineas(Collection $lineas): string
    {
        return $lineas
            ->map(fn (CompraLinea $l): string => rtrim(rtrim((string) $l->cantidad, '0'), '.').' '.$l->nombreLinea())
            ->implode(' · ');
    }

    /**
     * @param Collection<int, CompraLinea> $lineasObra
     *
     * @return Collection<int, User>
     */
    private function encargadosDeObras(Collection $lineasObra, Compra $compra): Collection
    {
        $obrasIds = $lineasObra
            ->map(fn (CompraLinea $l): int => $this->destinoDeLinea($compra, $l)->id)
            ->unique();

        return User::query()
            ->whereHas('obrasEncargadas', fn ($q) => $q->whereIn('proyectos.id', $obrasIds))
            ->where('is_active', true)
            ->get();
    }

    /**
     * Encargados de UNA obra — la de la cabecera, cuando la compra ni
     * siquiera tiene líneas todavía (captura diferida directo a obra).
     *
     * @return Collection<int, User>
     */
    private function encargadosDeObra(int $proyectoId): Collection
    {
        return User::query()
            ->whereHas('obrasEncargadas', fn ($q) => $q->whereKey($proyectoId))
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function usuariosConRol(string ...$roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->where('is_active', true)
            ->get();
    }

    private function destinoDeLinea(Compra $compra, CompraLinea $linea): Ubicacion
    {
        return $compra->destinoDeLinea($linea);
    }
}
