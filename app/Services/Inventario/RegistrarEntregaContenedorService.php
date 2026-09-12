<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Exceptions\Inventario\MovimientoInvalidoException;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Proyecto;
use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * REGRESO DE UN CONTENEDOR MÓVIL (Mauricio, 2026-09-10).
 *
 * "Es una pipa que siempre está llena en la bodega, hay que marcar cuándo
 * sale y cuándo regresa, si regresó llena o vacía para mandar a llenarla
 * otra vez."
 *
 * La idea que hace esto barato: NO hace falta preguntar con cuánto salió.
 * El contenedor es una bodega, así que su existencia ya lo dice. Lo único
 * que nadie sabe es con cuánto VOLVIÓ, y de esa resta sale lo entregado:
 *
 *     entregado = lo que cargaba − el nivel de regreso
 *
 * Ese entregado se mueve como cualquier despacho —contenedor → obra, al
 * costo promedio ponderado—, así que el costo de la obra y el reporte de
 * consumo salen solos, sin un solo cálculo nuevo.
 *
 * Regresar LLENA es legítimo: el viaje se canceló, no se entregó nada y
 * no se mueve stock. Regresar con MÁS de lo que llevaba no lo es: o la
 * rellenaron en el camino (y eso es una entrada que hay que registrar) o
 * el número está mal, y adivinar cuál sería inventar inventario.
 */
final readonly class RegistrarEntregaContenedorService
{
    /** Escala de cantidades (consistente con el resto de inventario). */
    private const int SCALE = 4;

    public function __construct(
        private RegistrarMovimientoService $inventario,
    ) {}

    /**
     * Marca el regreso del contenedor y descarga en la obra la diferencia.
     *
     * @param string $nivelRegreso con cuánto volvió (0 = vacío)
     *
     * @return numeric-string lo que quedó en la obra
     */
    public function registrarRegreso(
        Bodega $contenedor,
        Proyecto $obra,
        string $nivelRegreso,
        ?int $userId = null,
        ?string $fecha = null,
    ): string {
        if (! $contenedor->esMovil()) {
            throw MovimientoInvalidoException::bodegaNoEsContenedor($contenedor->nombre);
        }

        if ($contenedor->material_id === null) {
            throw MovimientoInvalidoException::contenedorSinMaterial($contenedor->nombre);
        }

        if (! is_numeric($nivelRegreso)) {
            throw MovimientoInvalidoException::cantidadInvalida($nivelRegreso);
        }

        $regreso = number_format((float) $nivelRegreso, self::SCALE, '.', '');

        if (bccomp($regreso, '0', self::SCALE) < 0) {
            throw MovimientoInvalidoException::nivelNegativo($regreso);
        }

        return DB::transaction(function () use ($contenedor, $obra, $regreso, $userId, $fecha): string {
            $llevaba = $contenedor->contenidoActual();

            if (bccomp($regreso, $llevaba, self::SCALE) > 0) {
                throw MovimientoInvalidoException::regresoConMasDeLoQueLlevaba(
                    $contenedor->nombre,
                    $regreso,
                    $llevaba,
                );
            }

            $entregado = bcsub($llevaba, $regreso, self::SCALE);

            // Volvió igual de llena: el viaje no dejó nada. No es un error
            // ni hay stock que mover — se registra el hecho y ya.
            if (bccomp($entregado, '0', self::SCALE) <= 0) {
                return '0';
            }

            $this->inventario->salidaDespacho(
                materialId: (int) $contenedor->material_id,
                origen: Ubicacion::bodega($contenedor->id),
                destino: Ubicacion::obra($obra->id),
                cantidad: $entregado,
                fecha: $fecha,
                userId: $userId,
                referencia: $contenedor,
            );

            if ($contenedor->fresh()?->estaVacio() === true) {
                $this->avisarQueHayQueLlenarlo($contenedor, $userId);
            }

            return $entregado;
        });
    }

    /**
     * DEVOLVER A BODEGA lo que el camión trajo de vuelta (Mauricio,
     * 2026-09-10).
     *
     * Es la puerta que cierra el círculo del reparto: si la obra recibió
     * 35 de los 40 sacos que subieron, los 5 restantes quedan como
     * existencia REAL del camión. Sin esta salida ese material quedaría
     * atrapado ahí para siempre — bien contado, pero inmovilizado, que es
     * peor que no tenerlo registrado.
     *
     * Va como TRASLADO y no como entrada: ese material nunca dejó de ser
     * nuestro, solo estuvo arriba de un camión. Su costo promedio viaja
     * intacto de vuelta a la bodega.
     *
     * Devuelve todo lo que el contenedor lleve encima, sin preguntar
     * material por material: si el camión volvió a base, volvió completo.
     *
     * @return array<int, array{material: string, cantidad: string}> lo que se bajó
     */
    public function devolverABodega(
        Bodega $contenedor,
        Bodega $bodega,
        ?int $userId = null,
        ?string $fecha = null,
    ): array {
        if (! $contenedor->esMovil()) {
            throw MovimientoInvalidoException::bodegaNoEsContenedor($contenedor->nombre);
        }

        if ($bodega->esMovil()) {
            throw MovimientoInvalidoException::devolucionAContenedor($bodega->nombre);
        }

        return DB::transaction(function () use ($contenedor, $bodega, $userId, $fecha): array {
            $aBordo = Existencia::query()
                ->where('bodega_id', $contenedor->id)
                ->where('cantidad', '>', 0)
                ->with('material:id,nombre')
                ->get();

            if ($aBordo->isEmpty()) {
                throw MovimientoInvalidoException::contenedorSinCarga($contenedor->nombre);
            }

            $devuelto = [];

            foreach ($aBordo as $existencia) {
                $cantidad = (string) $existencia->cantidad;

                if (! is_numeric($cantidad) || bccomp($cantidad, '0', self::SCALE) <= 0) {
                    continue;
                }

                $this->inventario->traslado(
                    materialId: $existencia->material_id,
                    origen: Ubicacion::bodega($contenedor->id),
                    destino: Ubicacion::bodega($bodega->id),
                    cantidad: $cantidad,
                    fecha: $fecha,
                    userId: $userId,
                    referencia: $contenedor,
                );

                $devuelto[] = [
                    'material' => $existencia->material->nombre,
                    'cantidad' => $cantidad,
                ];
            }

            return $devuelto;
        });
    }

    /**
     * "La pipa quedó vacía." Es el aviso que evita que mañana alguien la
     * mande a la obra con el tanque en cero: llega a quien la llena, no a
     * quien la usó.
     */
    private function avisarQueHayQueLlenarlo(Bodega $contenedor, ?int $actorId): void
    {
        $notificacion = Notification::make()
            ->title("{$contenedor->nombre} quedó vacía")
            ->body('Hay que mandarla a llenar antes del próximo viaje.')
            ->icon('heroicon-o-beaker')
            ->warning();

        // notifyNow (SÍNCRONO) y dentro de la transacción: si el despacho
        // se revierte, el aviso también. Nunca se avisa algo que no pasó.
        User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::BODEGUERO, Roles::RECEPCION]))
            ->where('is_active', true)
            ->get()
            ->reject(fn (User $user): bool => $user->id === $actorId)
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }
}
