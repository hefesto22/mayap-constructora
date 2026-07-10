<?php

declare(strict_types=1);

namespace App\Services\Requisiciones;

use App\Enums\EstadoRequisicion;
use App\Exceptions\Inventario\StockInsuficienteException;
use App\Exceptions\Requisiciones\RequisicionInvalidaException;
use App\Exceptions\Requisiciones\TransicionInvalidaException;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\RequisicionTransicion;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta el avance de una requisición por su máquina de estados, con la
 * regla de oro del sistema: cada transición valida que sea permitida,
 * registra al responsable en `requisicion_transiciones`, y en el despacho
 * mueve stock REAL bodega→obra valorado con el promedio ponderado (WAC).
 *
 * Es la única puerta para cambiar el estado de una requisición. Ningún
 * Resource toca `estado` directamente. Cada método público corre en una
 * transacción atómica: o avanza completo o no avanza.
 *
 * Flujo (docs/arquitectura/sistema-completo.md §3):
 *   autorizar → despachar → marcarEnTransito → recibir → conciliar
 * con `rechazar` disponible desde los estados tempranos, y la rama
 * automática a RequisicionCompra cuando la bodega no tiene stock.
 */
final readonly class TransicionarRequisicionService
{
    /** Escala de cantidades (consistente con inventario). */
    private const int SCALE_CANTIDAD = 4;

    public function __construct(
        private RegistrarMovimientoService $inventario,
        private NotificadorRequisiciones $notificador,
    ) {}

    /**
     * Solicitada → Autorizada. Fija la cantidad autorizada por línea
     * (puede ser igual o menor a la solicitada, nunca mayor). Si no se
     * provee una línea, se autoriza la cantidad solicitada completa.
     *
     * @param array<int, string> $cantidadesPorLinea requisicion_linea_id => cantidad
     */
    public function autorizar(
        Requisicion $requisicion,
        array $cantidadesPorLinea = [],
        ?int $userId = null,
        ?string $nota = null,
    ): void {
        $requisicion->loadMissing('lineas');
        $this->assertTieneLineas($requisicion);

        DB::transaction(function () use ($requisicion, $cantidadesPorLinea, $userId, $nota): void {
            foreach ($requisicion->lineas as $linea) {
                $autorizada = $cantidadesPorLinea[$linea->id] ?? (string) $linea->cantidad_solicitada;

                $this->validarCantidadAutorizada($autorizada, (string) $linea->cantidad_solicitada);

                $linea->cantidad_autorizada = $autorizada;
                $linea->save();
            }

            $this->aplicarTransicion($requisicion, EstadoRequisicion::Autorizada, $userId, $nota);
        });
    }

    /**
     * Autorizada (o RequisicionCompra) → Despachada. Por cada línea mueve
     * stock real de la bodega a la obra con su costo WAC y llena
     * cantidad_despachada. Si la bodega no tiene stock suficiente para
     * alguna línea, NO despacha nada y manda la requisición a
     * RequisicionCompra (Administración deberá comprar).
     */
    public function despachar(
        Requisicion $requisicion,
        Ubicacion $bodega,
        ?int $userId = null,
        ?string $nota = null,
    ): void {
        $requisicion->loadMissing('lineas');
        $this->assertTieneLineas($requisicion);

        $obra = Ubicacion::obra($requisicion->proyecto_id);

        try {
            DB::transaction(function () use ($requisicion, $bodega, $obra, $userId, $nota): void {
                foreach ($requisicion->lineas as $linea) {
                    $cantidad = (string) ($linea->cantidad_autorizada ?? $linea->cantidad_solicitada);

                    if (bccomp($cantidad, '0', self::SCALE_CANTIDAD) <= 0) {
                        continue;
                    }

                    $this->inventario->salidaDespacho(
                        materialId: $linea->material_id,
                        origen: $bodega,
                        destino: $obra,
                        cantidad: $cantidad,
                        userId: $userId,
                        referencia: $requisicion,
                    );

                    $linea->cantidad_despachada = $cantidad;
                    $linea->save();
                }

                $this->aplicarTransicion($requisicion, EstadoRequisicion::Despachada, $userId, $nota);
            });
        } catch (StockInsuficienteException $e) {
            // No hay stock para despachar → requisición de compra. El stock
            // movido en líneas previas se revirtió con el rollback de la
            // transacción; recargamos el estado real antes de transicionar.
            $requisicion->refresh();

            $motivo = $nota ?? "Sin stock en bodega para despachar: {$e->getMessage()}";

            DB::transaction(function () use ($requisicion, $userId, $motivo): void {
                $this->aplicarTransicion($requisicion, EstadoRequisicion::RequisicionCompra, $userId, $motivo);
            });
        }
    }

    /**
     * Autorizada / RequisicionCompra → Despachada por COMPRA DIRECTA A OBRA.
     *
     * La compra ya metió el material a la existencia de la obra (entrada de
     * inventario al costo real de factura) — aquí NO se mueve stock. Solo se
     * marcan las líneas como despachadas con lo que la compra cubrió y se
     * avanza el estado. La obra confirma después con `recibir` como siempre.
     *
     * Por material: despachada += min(pendiente, comprado). Si la compra no
     * cubre todo, la línea queda parcial y visible en la conciliación.
     *
     * Asume que el caller (ConfirmarCompraService) envuelve en transacción.
     *
     * @param array<int, string> $compradoPorMaterial material_id => cantidad
     */
    public function despacharPorCompraDirecta(
        Requisicion $requisicion,
        array $compradoPorMaterial,
        string $codigoCompra,
        ?int $userId = null,
    ): void {
        $requisicion->loadMissing('lineas');
        $this->assertTieneLineas($requisicion);

        foreach ($requisicion->lineas as $linea) {
            $comprado = $compradoPorMaterial[$linea->material_id] ?? '0';
            $autorizada = (string) ($linea->cantidad_autorizada ?? $linea->cantidad_solicitada);
            $pendiente = bcsub($autorizada, (string) $linea->cantidad_despachada, self::SCALE_CANTIDAD);

            if (bccomp($pendiente, '0', self::SCALE_CANTIDAD) <= 0 || bccomp($comprado, '0', self::SCALE_CANTIDAD) <= 0) {
                continue;
            }

            $aDespachar = bccomp($comprado, $pendiente, self::SCALE_CANTIDAD) < 0 ? $comprado : $pendiente;

            $linea->cantidad_despachada = bcadd(
                (string) $linea->cantidad_despachada,
                $aDespachar,
                self::SCALE_CANTIDAD,
            );
            $linea->save();
        }

        $this->aplicarTransicion(
            $requisicion,
            EstadoRequisicion::Despachada,
            $userId,
            "Despacho directo a obra por compra {$codigoCompra}.",
        );
    }

    /**
     * REVERSA del despacho por compra directa (compra ANULADA): resta lo
     * que la compra había marcado como despachado y regresa la requisición
     * a RequisicionCompra — hay que volver a comprar ese material.
     *
     * Es la única transición "hacia atrás" del sistema y NO pasa por
     * puedeTransicionarA (la máquina de estados solo avanza): la habilita
     * exclusivamente la anulación de la compra, y queda en la bitácora con
     * su nota. Asume que el caller (AnularCompraService) envuelve en
     * transacción.
     *
     * @param array<int, string> $compradoPorMaterial material_id => cantidad
     */
    public function revertirDespachoDirecto(
        Requisicion $requisicion,
        array $compradoPorMaterial,
        string $codigoCompra,
        ?int $userId = null,
    ): void {
        $requisicion->loadMissing('lineas');

        foreach ($requisicion->lineas as $linea) {
            $comprado = $compradoPorMaterial[$linea->material_id] ?? '0';

            if (bccomp($comprado, '0', self::SCALE_CANTIDAD) <= 0) {
                continue;
            }

            $nueva = bcsub((string) $linea->cantidad_despachada, $comprado, self::SCALE_CANTIDAD);

            $linea->cantidad_despachada = bccomp($nueva, '0', self::SCALE_CANTIDAD) > 0 ? $nueva : '0';
            $linea->save();
        }

        $origen = $requisicion->estado;
        $requisicion->estado = EstadoRequisicion::RequisicionCompra;
        $requisicion->save();

        RequisicionTransicion::create([
            'requisicion_id' => $requisicion->id,
            'estado_origen'  => $origen,
            'estado_destino' => EstadoRequisicion::RequisicionCompra,
            'user_id'        => $userId,
            'nota'           => "Reversa: la compra {$codigoCompra} fue anulada — el material debe comprarse de nuevo.",
        ]);

        $this->notificador->transicion($requisicion, EstadoRequisicion::RequisicionCompra, $userId);
    }

    /**
     * Despachada → EnTransito. El material salió hacia la obra.
     */
    public function marcarEnTransito(Requisicion $requisicion, ?int $userId = null, ?string $nota = null): void
    {
        DB::transaction(function () use ($requisicion, $userId, $nota): void {
            $this->aplicarTransicion($requisicion, EstadoRequisicion::EnTransito, $userId, $nota);
        });
    }

    /**
     * EnTransito → Recibida. La obra confirma cuánto llegó realmente por
     * línea. La conciliación (cerrar o marcar discrepancia) es un paso
     * aparte: `conciliar`.
     *
     * @param array<int, string> $cantidadesPorLinea requisicion_linea_id => cantidad recibida
     */
    public function recibir(
        Requisicion $requisicion,
        array $cantidadesPorLinea = [],
        ?int $userId = null,
        ?string $nota = null,
    ): void {
        $requisicion->loadMissing('lineas');

        DB::transaction(function () use ($requisicion, $cantidadesPorLinea, $userId, $nota): void {
            foreach ($requisicion->lineas as $linea) {
                $recibida = $cantidadesPorLinea[$linea->id] ?? (string) $linea->cantidad_despachada;

                if (bccomp($recibida, '0', self::SCALE_CANTIDAD) < 0) {
                    throw RequisicionInvalidaException::cantidadNegativa($recibida);
                }

                $linea->cantidad_recibida = $recibida;
                $linea->save();
            }

            $this->aplicarTransicion($requisicion, EstadoRequisicion::Recibida, $userId, $nota);
        });
    }

    /**
     * Recibida → Cerrada o Discrepancia. Compara, por línea, lo despachado
     * contra lo recibido: si TODO cuadra cierra la requisición; si algo no
     * cuadra la marca en Discrepancia (queda registrada la línea y el monto
     * exacto que no cuadró). Devuelve el estado final.
     */
    public function conciliar(Requisicion $requisicion, ?int $userId = null, ?string $nota = null): EstadoRequisicion
    {
        $requisicion->loadMissing('lineas');

        $hayDiscrepancia = $requisicion->lineas->contains(
            fn (RequisicionLinea $linea): bool => bccomp(
                (string) $linea->cantidad_despachada,
                (string) $linea->cantidad_recibida,
                self::SCALE_CANTIDAD,
            ) !== 0,
        );

        $destino = $hayDiscrepancia ? EstadoRequisicion::Discrepancia : EstadoRequisicion::Cerrada;

        DB::transaction(function () use ($requisicion, $destino, $userId, $nota): void {
            $this->aplicarTransicion($requisicion, $destino, $userId, $nota);
        });

        return $destino;
    }

    /**
     * Rechaza la requisición desde un estado temprano (Solicitada,
     * Autorizada o RequisicionCompra).
     */
    public function rechazar(Requisicion $requisicion, ?int $userId = null, ?string $nota = null): void
    {
        DB::transaction(function () use ($requisicion, $userId, $nota): void {
            $this->aplicarTransicion($requisicion, EstadoRequisicion::Rechazada, $userId, $nota);
        });
    }

    /**
     * Núcleo de la máquina de estados: valida que la transición sea
     * permitida, cambia el estado y escribe el renglón de la bitácora con
     * el responsable. Asume que el caller la envuelve en una transacción.
     */
    private function aplicarTransicion(
        Requisicion $requisicion,
        EstadoRequisicion $destino,
        ?int $userId,
        ?string $nota,
    ): void {
        $origen = $requisicion->estado;

        if (! $origen->puedeTransicionarA($destino)) {
            throw new TransicionInvalidaException($requisicion->codigo, $origen, $destino);
        }

        $requisicion->estado = $destino;
        $requisicion->save();

        RequisicionTransicion::create([
            'requisicion_id' => $requisicion->id,
            'estado_origen'  => $origen,
            'estado_destino' => $destino,
            'user_id'        => $userId,
            'nota'           => $nota,
        ]);

        // Campanita al rol que tiene el siguiente paso. Corre DENTRO de la
        // transacción del caller: si la transición se revierte, las
        // notificaciones también (nunca avisa algo que no pasó).
        $this->notificador->transicion($requisicion, $destino, $userId);
    }

    private function validarCantidadAutorizada(string $autorizada, string $solicitada): void
    {
        if (bccomp($autorizada, '0', self::SCALE_CANTIDAD) < 0) {
            throw RequisicionInvalidaException::cantidadNegativa($autorizada);
        }

        if (bccomp($autorizada, $solicitada, self::SCALE_CANTIDAD) > 0) {
            throw RequisicionInvalidaException::autorizadaExcedeSolicitada($autorizada, $solicitada);
        }
    }

    private function assertTieneLineas(Requisicion $requisicion): void
    {
        if ($requisicion->lineas->isEmpty()) {
            throw RequisicionInvalidaException::sinLineas($requisicion->codigo);
        }
    }
}
