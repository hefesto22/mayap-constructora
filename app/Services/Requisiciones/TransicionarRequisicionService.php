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
