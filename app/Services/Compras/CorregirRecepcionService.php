<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoCorregibleException;
use App\Exceptions\Inventario\StockInsuficienteException;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Support\Permisos;
use App\Support\Roles;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Corrección de conteos de recepción — el caso real del día a día:
 * "dije que llegaron 40 pero eran 60" (o al revés).
 *
 * Dos momentos con costos MUY distintos:
 *
 *  - Compra POR RECIBIR: el stock aún no entró → corregir es solo
 *    re-capturar el número. Lo hace quien puede VERIFICAR esa línea
 *    (mismo permiso y alcance de la verificación).
 *
 *  - Compra CONFIRMADA: el stock YA entró → la corrección genera un
 *    movimiento de inventario por la DIFERENCIA:
 *      · contó de menos (40→60): entran 20 al MISMO costo efectivo de la
 *        factura (flete/descuento prorrateados incluidos);
 *      · contó de más (60→55): salen 5 retirando el VALOR EXACTO (motor
 *        de anulación) — si ese stock ya se usó, se bloquea y lo dice.
 *    Requiere el permiso "Corregir recepción" (gerencia por defecto,
 *    ajustable desde Roles → Personalizados).
 *
 * La CxP NO se toca: la factura del proveedor no cambió — lo que cambia
 * es el reclamo. La requisición enlazada ajusta su despacho.
 */
final readonly class CorregirRecepcionService
{
    private const int SCALE_CANTIDAD = 4;

    private const int SCALE_MONTO = 2;

    private const int SCALE_INTERNO = 12;

    /** @var list<EstadoCompra> */
    private const array ESTADOS_CORREGIBLES = [EstadoCompra::PorRecibir, EstadoCompra::Confirmada];

    public function __construct(
        private RegistrarMovimientoService $inventario,
        private ConfirmarCompraService $confirmar,
        private AlcanceDestinoCompra $alcance,
    ) {}

    /**
     * Corrige el conteo de las líneas dadas. Motivo obligatorio: queda en
     * el rastro de los movimientos de ajuste.
     *
     * @param array<int, string|float|int> $corregidoPorLinea linea_id => cantidad recibida REAL
     */
    public function corregir(Compra $compra, array $corregidoPorLinea, string $motivo, User $corrector): void
    {
        if ($corregidoPorLinea === []) {
            throw CompraNoCorregibleException::sinLineasCapturadas($compra->codigo);
        }

        if (trim($motivo) === '') {
            throw CompraNoCorregibleException::motivoObligatorio();
        }

        if (! in_array($compra->estado, self::ESTADOS_CORREGIBLES, strict: true)) {
            throw CompraNoCorregibleException::estadoInvalido($compra->codigo, $compra->estado);
        }

        DB::transaction(function () use ($compra, $corregidoPorLinea, $motivo, $corrector): void {
            $bloqueada = Compra::query()
                ->whereKey($compra->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($bloqueada->estado, self::ESTADOS_CORREGIBLES, strict: true)) {
                throw CompraNoCorregibleException::estadoInvalido($compra->codigo, $bloqueada->estado);
            }

            $compra->load('lineas.material:id,nombre,consumo_inmediato');

            $confirmada = $bloqueada->estado === EstadoCompra::Confirmada;
            $costos = $confirmada ? $this->confirmar->costosEfectivosPorLinea($compra) : [];

            foreach ($corregidoPorLinea as $lineaId => $cantidad) {
                $linea = $compra->lineas->firstWhere('id', $lineaId);

                if (! $linea instanceof CompraLinea) {
                    throw CompraNoCorregibleException::lineaAjena($compra->codigo, (int) $lineaId);
                }

                if (! $linea->verificada()) {
                    throw CompraNoCorregibleException::lineaSinVerificar($compra->codigo, $linea->material->nombre);
                }

                if (! $this->puedeCorregir($corrector, $bloqueada->estado, $compra, $linea)) {
                    throw CompraNoCorregibleException::sinPermiso($compra->codigo);
                }

                if (! is_numeric($cantidad) || bccomp((string) $cantidad, '0', self::SCALE_CANTIDAD) < 0) {
                    throw CompraNoCorregibleException::cantidadInvalida((string) $cantidad);
                }

                $diferencia = bcsub((string) $cantidad, (string) $linea->cantidad_recibida, self::SCALE_CANTIDAD);

                if (bccomp($diferencia, '0', self::SCALE_CANTIDAD) === 0) {
                    continue; // nada cambió en esta línea
                }

                // Confirmada: el stock ya entró → mover la diferencia.
                if ($confirmada) {
                    $this->ajustarInventario($compra, $linea, $diferencia, $costos[$linea->id], $motivo, $corrector->id);
                    $this->ajustarRequisicion($compra, $linea, $diferencia);
                }

                $linea->cantidad_recibida = (string) $cantidad;
                $linea->verificada_at = now();
                $linea->verificada_por = $corrector->id;
                $linea->save();
            }
        });
    }

    /**
     * ¿El usuario puede corregir ESTA línea en este estado?
     *
     *  - Por recibir → mismo régimen que verificar (permiso + alcance).
     *  - Confirmada  → permiso "Corregir recepción" + alcance (gerencia y
     *    admin, con el permiso, corrigen cualquier destino).
     */
    public function puedeCorregir(User $user, EstadoCompra $estado, Compra $compra, CompraLinea $linea): bool
    {
        $esRespaldo = $user->hasAnyRole([Roles::GERENCIA, Utils::getSuperAdminName()]);

        if ($estado === EstadoCompra::PorRecibir) {
            return $esRespaldo
                || ($user->can(Permisos::VERIFICAR_RECEPCION_COMPRA) && $this->alcance->alcanza($user, $compra, $linea));
        }

        return $user->can(Permisos::CORREGIR_RECEPCION_COMPRA)
            && ($esRespaldo || $this->alcanceParaCorregir($user, $compra, $linea));
    }

    /**
     * Líneas ya verificadas que este usuario puede corregir — para el
     * modal de la acción (cada quien corrige solo lo suyo).
     *
     * @return Collection<int, CompraLinea>
     */
    public function lineasCorregiblesPara(User $user, Compra $compra): Collection
    {
        if (! in_array($compra->estado, self::ESTADOS_CORREGIBLES, strict: true)) {
            return new Collection;
        }

        $compra->loadMissing('lineas.material:id,nombre,consumo_inmediato');

        return $compra->lineas
            ->filter(fn (CompraLinea $l): bool => $l->verificada())
            ->filter(fn (CompraLinea $l): bool => $this->puedeCorregir($user, $compra->estado, $compra, $l))
            ->values();
    }

    private function alcanceParaCorregir(User $user, Compra $compra, CompraLinea $linea): bool
    {
        return $this->alcance->alcanza($user, $compra, $linea);
    }

    /**
     * Mueve la DIFERENCIA de inventario al costo efectivo de la factura.
     * Replica los pares de consumo inmediato de confirmación/anulación
     * para que el costo imputado a la obra quede correcto.
     */
    private function ajustarInventario(
        Compra $compra,
        CompraLinea $linea,
        string $diferencia,
        string $costoEfectivo,
        string $motivo,
        ?int $userId,
    ): void {
        $destino = $compra->destinoDeLinea($linea);
        $nota = "Corrección de conteo de la compra {$compra->codigo}: {$motivo}";

        // Contó de MENOS: la diferencia entra como la compra original.
        if (bccomp($diferencia, '0', self::SCALE_CANTIDAD) > 0) {
            $this->inventario->entradaCompra(
                materialId: $linea->material_id,
                destino: $destino,
                cantidad: $diferencia,
                costoUnitario: $costoEfectivo,
                userId: $userId,
                referencia: $compra,
            );

            if ($linea->material->consumo_inmediato) {
                $this->inventario->consumoObra(
                    materialId: $linea->material_id,
                    origen: $destino,
                    cantidad: $diferencia,
                    motivo: $nota,
                    userId: $userId,
                    referencia: $compra,
                );
            }

            return;
        }

        // Contó de MÁS: sale la diferencia retirando el valor EXACTO que
        // la entrada registró (misma matemática que la anulación).
        $cantidad = bcmul($diferencia, '-1', self::SCALE_CANTIDAD);
        $valor = $this->bcround(bcmul($cantidad, $costoEfectivo, self::SCALE_INTERNO), self::SCALE_MONTO);

        try {
            // Consumo inmediato: la existencia quedó en cero (se consumió al
            // recibir) — reponer primero para poder retirar el valor, igual
            // que hace la anulación de compras.
            if ($linea->material->consumo_inmediato) {
                $this->inventario->ajustePositivo(
                    materialId: $linea->material_id,
                    destino: $destino,
                    cantidad: $cantidad,
                    costoUnitario: $costoEfectivo,
                    motivo: $nota,
                    userId: $userId,
                );
            }

            $this->inventario->anulacionCompra(
                materialId: $linea->material_id,
                origen: $destino,
                cantidad: $cantidad,
                valorARevertir: $valor,
                motivo: $nota,
                userId: $userId,
                referencia: $compra,
            );
        } catch (StockInsuficienteException $e) {
            // Rollback total: nada quedó a medias.
            throw CompraNoCorregibleException::stockYaUsado($compra->codigo, $e->getMessage());
        }
    }

    /**
     * La requisición enlazada despachó lo RECIBIDO — si el conteo cambió,
     * el despacho se ajusta en la misma medida (nunca bajo cero).
     */
    private function ajustarRequisicion(Compra $compra, CompraLinea $linea, string $diferencia): void
    {
        if ($compra->requisicion_id === null) {
            return;
        }

        $compra->loadMissing('requisicion');

        $destino = $compra->destinoDeLinea($linea);

        if (! $destino->esIgualA(Ubicacion::obra($compra->requisicion->proyecto_id))) {
            return;
        }

        $reqLinea = $compra->requisicion->lineas()
            ->where('material_id', $linea->material_id)
            ->first();

        if ($reqLinea === null) {
            return;
        }

        $nueva = bcadd((string) $reqLinea->cantidad_despachada, $diferencia, self::SCALE_CANTIDAD);

        $reqLinea->cantidad_despachada = bccomp($nueva, '0', self::SCALE_CANTIDAD) < 0 ? '0' : $nueva;
        $reqLinea->save();
    }

    private function bcround(string $value, int $scale): string
    {
        $factor = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', self::SCALE_INTERNO) >= 0) {
            return bcadd($value, $factor, $scale);
        }

        return bcsub($value, $factor, $scale);
    }
}
