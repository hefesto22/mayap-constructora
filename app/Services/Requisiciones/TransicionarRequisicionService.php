<?php

declare(strict_types=1);

namespace App\Services\Requisiciones;

use App\Enums\EstadoRequisicion;
use App\Enums\OrigenDespacho;
use App\Exceptions\Inventario\StockInsuficienteException;
use App\Exceptions\Requisiciones\RequisicionInvalidaException;
use App\Exceptions\Requisiciones\TransicionInvalidaException;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\RequisicionTransicion;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use Illuminate\Support\Carbon;
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
     * Si la fecha necesaria ya venció, NO se autoriza: primero hay que
     * reprogramar (con motivo en bitácora) o rechazar — autorizar un
     * pedido vencido "como si nada" despacharía material que quizá la
     * obra ya resolvió por otro lado.
     *
     * @param array<int, string> $cantidadesPorLinea requisicion_linea_id => cantidad
     */
    public function autorizar(
        Requisicion $requisicion,
        array $cantidadesPorLinea = [],
        ?int $userId = null,
        ?string $nota = null,
    ): void {
        if ($requisicion->fechaNecesariaVencida()) {
            throw RequisicionInvalidaException::vencidaSinReprogramar(
                $requisicion->codigo,
                $requisicion->fecha_necesaria->format('d/m/Y'),
            );
        }

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

                // Salió de bodega: hay un tramo real de carretera que
                // registrar, así que esta requisición SÍ pasa por tránsito.
                $requisicion->origen_despacho = OrigenDespacho::Bodega;

                $this->aplicarTransicion($requisicion, EstadoRequisicion::Despachada, $userId, $nota);
            });
        } catch (StockInsuficienteException $e) {
            // No hay stock para despachar → requisición de compra. El stock
            // movido en líneas previas se revirtió con el rollback de la
            // transacción; recargamos el estado real antes de transicionar.
            $requisicion->refresh();

            // Reintento de despacho SIN stock nuevo: ya estaba en
            // Requisición de compra — no hay transición que aplicar (sería
            // RequisicionCompra → RequisicionCompra: inválida). La acción
            // de Filament le muestra el aviso de "sin stock" al usuario.
            if ($requisicion->estado === EstadoRequisicion::RequisicionCompra) {
                return;
            }

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

        // El material NO viajó desde una bodega nuestra: lo dejó el
        // proveedor en el sitio. Marcar el origen es lo que cierra el
        // camino a "En tránsito" (bug de REQ-2026-00005). Si alguna parte
        // ya había salido de bodega, manda bodega: ese tramo sí existió.
        if ($requisicion->origen_despacho !== OrigenDespacho::Bodega) {
            $requisicion->origen_despacho = OrigenDespacho::CompraDirecta;
        }

        // La espera terminó: el material llegó. Se limpia el seguimiento
        // de llegada para que el cron deje de perseguir esta requisición.
        $requisicion->aviso_llegada_obra_at = null;
        $requisicion->fecha_estimada_llegada = null;

        $this->aplicarTransicion(
            $requisicion,
            EstadoRequisicion::Despachada,
            $userId,
            "Despacho directo a obra por compra {$codigoCompra}.",
        );
    }

    /**
     * AUTO-RECEPCIÓN de compra directa (decisión Mauricio 2026-08-07, "B3").
     *
     * Cuando quien verificó la recepción de la compra es el encargado de
     * ESA obra, el material ya se contó en el sitio, contra la factura y
     * con su firma (`verificada_por`). Pedirle que lo cuente OTRA VEZ en la
     * requisición el mismo día es puro doble trabajo: la requisición se da
     * por recibida sola y se concilia.
     *
     * NO aplica —y la obra sí tiene que confirmar a mano— cuando:
     *  - quien verificó fue la oficina (gerencia/recepción con pase
     *    universal): nadie en la obra vio ese material; o
     *  - la compra no cubrió todo lo pendiente: llegó parcial y hay que
     *    capturar cuánto llegó de verdad.
     *
     * Asume que el caller (ConfirmarCompraService) envuelve en transacción.
     *
     * @return bool ¿Se dio por recibida sola?
     */
    public function autoRecibirPorCompraDirecta(
        Requisicion $requisicion,
        User $verificador,
        string $codigoCompra,
    ): bool {
        if ($requisicion->estado !== EstadoRequisicion::Despachada || ! $requisicion->esDespachoDirecto()) {
            return false;
        }

        $requisicion->loadMissing('proyecto');

        if (! $requisicion->proyecto->esEncargado($verificador)) {
            return false;
        }

        if ($this->tienePendienteDeDespacho($requisicion)) {
            return false;
        }

        $nota = "Recepción confirmada en obra al verificar la compra {$codigoCompra}.";

        foreach ($requisicion->lineas as $linea) {
            $linea->cantidad_recibida = (string) $linea->cantidad_despachada;
            $linea->save();
        }

        $this->aplicarTransicion($requisicion, EstadoRequisicion::Recibida, $verificador->id, $nota);

        // Misma puerta de siempre: la regla de "cuadra / no cuadra" vive en
        // un solo lugar. Como lo recibido se tomó de lo despachado, cierra.
        $this->conciliar(
            $requisicion,
            $verificador->id,
            "Conciliada automáticamente: lo contado al verificar {$codigoCompra} cuadra con lo despachado.",
        );

        return true;
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
        // Se deshizo el despacho: el origen vuelve a estar sin decidir (la
        // requisición puede terminar saliendo de bodega esta vez).
        $requisicion->origen_despacho = null;
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
     * Despachada → EnTransito. El material salió DE BODEGA hacia la obra.
     *
     * Solo existe en la vía bodega. En una compra directa el proveedor
     * entregó en el sitio: no hay tramo que marcar, y el guard explícito
     * da un mensaje claro en vez del genérico de transición inválida.
     */
    public function marcarEnTransito(Requisicion $requisicion, ?int $userId = null, ?string $nota = null): void
    {
        if ($requisicion->esDespachoDirecto()) {
            throw RequisicionInvalidaException::transitoEnDespachoDirecto($requisicion->codigo);
        }

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
     * REPROGRAMA la fecha necesaria de una requisición Solicitada cuya
     * fecha venció sin atenderse. No cambia el estado: deja un renglón
     * Solicitada → Solicitada en la bitácora con el responsable, la fecha
     * anterior, la nueva y el motivo (obligatorio).
     *
     * Es la única puerta para mover la fecha una vez vencida — el
     * formulario de edición bloquea el campo en ese caso. No pasa por
     * aplicarTransicion porque la máquina de estados solo modela avances
     * (Solicitada → Solicitada sería inválida); la bitácora sí registra
     * el evento, que es lo que importa para la trazabilidad.
     */
    public function reprogramar(
        Requisicion $requisicion,
        Carbon $nuevaFecha,
        string $motivo,
        ?int $userId = null,
    ): void {
        if ($requisicion->estado !== EstadoRequisicion::Solicitada) {
            throw RequisicionInvalidaException::soloSolicitadaSeReprograma(
                $requisicion->codigo,
                $requisicion->estado->getLabel(),
            );
        }

        if (trim($motivo) === '') {
            throw RequisicionInvalidaException::motivoReprogramacionRequerido();
        }

        if ($nuevaFecha->lt(today())) {
            throw RequisicionInvalidaException::fechaReprogramadaEnPasado($nuevaFecha->format('d/m/Y'));
        }

        DB::transaction(function () use ($requisicion, $nuevaFecha, $motivo, $userId): void {
            $anterior = $requisicion->fecha_necesaria->format('d/m/Y');

            $requisicion->fecha_necesaria = $nuevaFecha;
            $requisicion->save();

            RequisicionTransicion::create([
                'requisicion_id' => $requisicion->id,
                'estado_origen'  => EstadoRequisicion::Solicitada,
                'estado_destino' => EstadoRequisicion::Solicitada,
                'user_id'        => $userId,
                'nota'           => "Fecha necesaria reprogramada: {$anterior} → "
                    .$nuevaFecha->format('d/m/Y').". Motivo: {$motivo}",
            ]);
        });
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

        // El mapa se consulta CON el origen del despacho: es lo que impide
        // que una compra directa se vaya por "En tránsito".
        if (! $requisicion->puedeTransicionarA($destino)) {
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

    /**
     * ¿Queda algo por despachar? (autorizado − despachado > 0 en alguna
     * línea). Lo consume la auto-recepción de compra directa: solo se da
     * por recibida sola cuando la compra cubrió TODO — si vino parcial, la
     * obra tiene que confirmar a mano lo que realmente llegó.
     *
     * Vive en el Service y no en el modelo por dos razones: el modelo solo
     * persiste y consulta, y la aritmética bcmath de cantidades pertenece
     * a esta capa — que es donde phpstan.neon documenta que estos strings
     * salen de columnas NUMERIC con CHECK de no-negatividad.
     */
    private function tienePendienteDeDespacho(Requisicion $requisicion): bool
    {
        $requisicion->loadMissing('lineas');

        return $requisicion->lineas->contains(function (RequisicionLinea $linea): bool {
            $autorizada = (string) ($linea->cantidad_autorizada ?? $linea->cantidad_solicitada);

            return bccomp($autorizada, (string) $linea->cantidad_despachada, self::SCALE_CANTIDAD) > 0;
        });
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
