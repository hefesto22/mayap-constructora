<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\TipoMovimientoInventario;
use App\Exceptions\Inventario\MovimientoInvalidoException;
use App\Exceptions\Inventario\StockInsuficienteException;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Service de inventario — ÚNICA puerta de escritura de existencias y
 * movimientos. Ningún Resource, Job ni Command modifica una existencia
 * directamente: todo pasa por aquí.
 *
 * COSTEO — PROMEDIO PONDERADO MÓVIL (ADR-0002 §3) por método PROPORCIONAL:
 * cuando salen `q` unidades de una ubicación con `cantidad` y `valor_total`,
 * el valor que se retira es exactamente proporcional:
 *
 *     valor_retirado = valor_total × q / cantidad
 *     nuevo_valor    = valor_total − valor_retirado
 *     nueva_cantidad = cantidad − q
 *
 * Así el promedio (valor/cantidad) queda INVARIANTE ante salidas — que es
 * la definición del WAC — y no se acumula drift de céntimos. En un traslado,
 * el valor que sale del origen es idéntico al que entra al destino: el valor
 * total del sistema se conserva al céntimo.
 *
 * En una ENTRADA por compra, el valor agregado lo define el costo de compra
 * (q × costo_unitario) y el promedio del destino se mueve hacia ese costo.
 *
 * CONCURRENCIA: cada operación corre en una transacción con `lockForUpdate`
 * sobre las filas de existencia afectadas. Dos despachos simultáneos del
 * mismo material+ubicación se serializan, evitando sobreventa y promedios
 * corruptos.
 *
 * Toda la aritmética usa bcmath con escala interna 12 y se redondea
 * (half-up) a 2 decimales en montos y 4 en cantidades solo al persistir,
 * consistente con CalcularPrecioFichaService.
 */
final class RegistrarMovimientoService
{
    /** Escala interna para cálculos intermedios. */
    private const int SCALE_INTERNO = 12;

    /** Escala de montos en HNL (centavos). */
    private const int SCALE_MONTO = 2;

    /** Escala de cantidades (materiales fraccionarios: m³, kg, ml). */
    private const int SCALE_CANTIDAD = 4;

    /**
     * Entrada por compra: el stock aparece en una ubicación (típicamente
     * bodega) con un costo de compra que alimenta el promedio ponderado.
     */
    public function entradaCompra(
        int $materialId,
        Ubicacion $destino,
        string $cantidad,
        string $costoUnitario,
        ?string $fecha = null,
        ?int $userId = null,
        ?Model $referencia = null,
    ): ResultadoMovimiento {
        return $this->registrar(
            tipo: TipoMovimientoInventario::EntradaCompra,
            materialId: $materialId,
            origen: null,
            destino: $destino,
            cantidad: $cantidad,
            costoPropio: $costoUnitario,
            motivo: null,
            fecha: $fecha,
            userId: $userId,
            referencia: $referencia,
        );
    }

    /**
     * Despacho a obra: el material sale de la bodega central y entra a la
     * existencia de la obra arrastrando su costo promedio. Es el evento
     * que imputa costo al proyecto (ADR-0002 §2).
     */
    public function salidaDespacho(
        int $materialId,
        Ubicacion $origen,
        Ubicacion $destino,
        string $cantidad,
        ?string $fecha = null,
        ?int $userId = null,
        ?Model $referencia = null,
    ): ResultadoMovimiento {
        return $this->registrar(
            tipo: TipoMovimientoInventario::SalidaDespacho,
            materialId: $materialId,
            origen: $origen,
            destino: $destino,
            cantidad: $cantidad,
            costoPropio: null,
            motivo: null,
            fecha: $fecha,
            userId: $userId,
            referencia: $referencia,
        );
    }

    /**
     * Traslado entre dos ubicaciones, sin impacto en costo del proyecto.
     */
    public function traslado(
        int $materialId,
        Ubicacion $origen,
        Ubicacion $destino,
        string $cantidad,
        ?string $fecha = null,
        ?int $userId = null,
        ?Model $referencia = null,
    ): ResultadoMovimiento {
        return $this->registrar(
            tipo: TipoMovimientoInventario::Traslado,
            materialId: $materialId,
            origen: $origen,
            destino: $destino,
            cantidad: $cantidad,
            costoPropio: null,
            motivo: null,
            fecha: $fecha,
            userId: $userId,
            referencia: $referencia,
        );
    }

    /**
     * Consumo físico en obra (gasto día a día). Baja la existencia de la
     * obra sin destino.
     */
    public function consumoObra(
        int $materialId,
        Ubicacion $origen,
        string $cantidad,
        ?string $motivo = null,
        ?string $fecha = null,
        ?int $userId = null,
        ?Model $referencia = null,
    ): ResultadoMovimiento {
        return $this->registrar(
            tipo: TipoMovimientoInventario::ConsumoObra,
            materialId: $materialId,
            origen: $origen,
            destino: null,
            cantidad: $cantidad,
            costoPropio: null,
            motivo: $motivo,
            fecha: $fecha,
            userId: $userId,
            referencia: $referencia,
        );
    }

    /**
     * Devolución de obra a bodega: material no usado que regresa.
     */
    public function devolucion(
        int $materialId,
        Ubicacion $origen,
        Ubicacion $destino,
        string $cantidad,
        ?string $fecha = null,
        ?int $userId = null,
        ?Model $referencia = null,
    ): ResultadoMovimiento {
        return $this->registrar(
            tipo: TipoMovimientoInventario::Devolucion,
            materialId: $materialId,
            origen: $origen,
            destino: $destino,
            cantidad: $cantidad,
            costoPropio: null,
            motivo: null,
            fecha: $fecha,
            userId: $userId,
            referencia: $referencia,
        );
    }

    /**
     * Ajuste positivo: alta por conteo físico/hallazgo. Requiere motivo.
     */
    public function ajustePositivo(
        int $materialId,
        Ubicacion $destino,
        string $cantidad,
        string $costoUnitario,
        string $motivo,
        ?string $fecha = null,
        ?int $userId = null,
    ): ResultadoMovimiento {
        return $this->registrar(
            tipo: TipoMovimientoInventario::AjustePositivo,
            materialId: $materialId,
            origen: null,
            destino: $destino,
            cantidad: $cantidad,
            costoPropio: $costoUnitario,
            motivo: $motivo,
            fecha: $fecha,
            userId: $userId,
            referencia: null,
        );
    }

    /**
     * Ajuste negativo / merma: baja por daño, pérdida o contingencia.
     * Requiere motivo.
     */
    public function ajusteNegativo(
        int $materialId,
        Ubicacion $origen,
        string $cantidad,
        string $motivo,
        ?string $fecha = null,
        ?int $userId = null,
    ): ResultadoMovimiento {
        return $this->registrar(
            tipo: TipoMovimientoInventario::AjusteNegativo,
            materialId: $materialId,
            origen: $origen,
            destino: null,
            cantidad: $cantidad,
            costoPropio: null,
            motivo: $motivo,
            fecha: $fecha,
            userId: $userId,
            referencia: null,
        );
    }

    /**
     * Núcleo: valida, bloquea existencias, aplica el efecto WAC y escribe
     * el renglón del libro mayor, todo en una transacción.
     */
    private function registrar(
        TipoMovimientoInventario $tipo,
        int $materialId,
        ?Ubicacion $origen,
        ?Ubicacion $destino,
        string $cantidad,
        ?string $costoPropio,
        ?string $motivo,
        ?string $fecha,
        ?int $userId,
        ?Model $referencia,
    ): ResultadoMovimiento {
        $this->validar($tipo, $origen, $destino, $cantidad, $costoPropio, $motivo);

        return DB::transaction(function () use (
            $tipo,
            $materialId,
            $origen,
            $destino,
            $cantidad,
            $costoPropio,
            $motivo,
            $fecha,
            $userId,
            $referencia
        ): ResultadoMovimiento {
            $valorMovido = null;
            $saldoOrigen = null;
            $saldoDestino = null;

            // ─── Lado ORIGEN: retiro proporcional ──────────────────
            if ($tipo->tieneOrigen() && $origen !== null) {
                $existenciaOrigen = $this->existenciaBloqueada($materialId, $origen, crear: false);

                if ($existenciaOrigen === null) {
                    throw new StockInsuficienteException(
                        materialId: $materialId,
                        ubicacion: $origen->descripcion(),
                        solicitado: $cantidad,
                        disponible: '0',
                    );
                }

                if (bccomp((string) $existenciaOrigen->cantidad, $cantidad, self::SCALE_CANTIDAD) < 0) {
                    throw new StockInsuficienteException(
                        materialId: $materialId,
                        ubicacion: $origen->descripcion(),
                        solicitado: $cantidad,
                        disponible: (string) $existenciaOrigen->cantidad,
                    );
                }

                $valorMovido = $this->valorProporcional(
                    (string) $existenciaOrigen->valor_total,
                    (string) $existenciaOrigen->cantidad,
                    $cantidad,
                );

                $existenciaOrigen->cantidad = bcsub((string) $existenciaOrigen->cantidad, $cantidad, self::SCALE_CANTIDAD);
                $existenciaOrigen->valor_total = bcsub((string) $existenciaOrigen->valor_total, $valorMovido, self::SCALE_MONTO);
                $existenciaOrigen->save();

                $saldoOrigen = SaldoUbicacion::desdeExistencia($existenciaOrigen->refresh(), $origen);
            }

            // ─── Costo unitario aplicado al movimiento ─────────────
            // Entrada con costo propio → ese costo. Transferencia/salida →
            // el costo promedio que arrastra el origen (valorMovido / q).
            if ($tipo->defineCostoPropio()) {
                $costoUnitario = $this->bcround((string) $costoPropio, self::SCALE_CANTIDAD);
                $valorEntrada = $this->bcround(bcmul($cantidad, (string) $costoPropio, self::SCALE_INTERNO), self::SCALE_MONTO);
            } else {
                $valorEntrada = (string) $valorMovido;
                $costoUnitario = $this->bcround(bcdiv($valorEntrada, $cantidad, self::SCALE_INTERNO), self::SCALE_CANTIDAD);
            }

            // ─── Lado DESTINO: alta del valor correspondiente ──────
            if ($tipo->tieneDestino() && $destino !== null) {
                $existenciaDestino = $this->existenciaBloqueada($materialId, $destino, crear: true);

                $existenciaDestino->cantidad = bcadd((string) $existenciaDestino->cantidad, $cantidad, self::SCALE_CANTIDAD);
                $existenciaDestino->valor_total = bcadd((string) $existenciaDestino->valor_total, $valorEntrada, self::SCALE_MONTO);
                $existenciaDestino->save();

                $saldoDestino = SaldoUbicacion::desdeExistencia($existenciaDestino->refresh(), $destino);
            }

            // ─── Renglón del libro mayor (inmutable) ───────────────
            $atributos = array_merge(
                [
                    'tipo'           => $tipo,
                    'material_id'    => $materialId,
                    'cantidad'       => $cantidad,
                    'costo_unitario' => $costoUnitario,
                    'valor_total'    => $valorEntrada,
                    'motivo'         => $motivo,
                    'user_id'        => $userId,
                    'fecha'          => $fecha ?? now()->toDateString(),
                ],
                $origen?->atributosMovimiento('origen') ?? [],
                $destino?->atributosMovimiento('destino') ?? [],
            );

            $movimiento = new MovimientoInventario($atributos);

            if ($referencia !== null) {
                $movimiento->referencia()->associate($referencia);
            }

            $movimiento->save();

            return new ResultadoMovimiento(
                movimientoId: $movimiento->id,
                tipo: $tipo,
                costoUnitarioAplicado: $costoUnitario,
                origen: $saldoOrigen,
                destino: $saldoDestino,
            );
        });
    }

    /**
     * Validaciones de dominio previas a tocar la DB (fail fast).
     */
    private function validar(
        TipoMovimientoInventario $tipo,
        ?Ubicacion $origen,
        ?Ubicacion $destino,
        string $cantidad,
        ?string $costoPropio,
        ?string $motivo,
    ): void {
        if (bccomp($cantidad, '0', self::SCALE_CANTIDAD) <= 0) {
            throw MovimientoInvalidoException::cantidadInvalida($cantidad);
        }

        if ($tipo->defineCostoPropio()) {
            if ($costoPropio === null || bccomp($costoPropio, '0', self::SCALE_CANTIDAD) < 0) {
                throw MovimientoInvalidoException::costoNegativo((string) $costoPropio);
            }
        }

        if ($tipo->requiereMotivo() && ($motivo === null || trim($motivo) === '')) {
            throw MovimientoInvalidoException::motivoRequerido($tipo->getLabel());
        }

        if ($origen !== null && $destino !== null && $origen->esIgualA($destino)) {
            throw MovimientoInvalidoException::mismaUbicacion($origen->descripcion());
        }
    }

    /**
     * Localiza (o crea) la fila de existencia de una ubicación y la bloquea
     * con lockForUpdate dentro de la transacción en curso.
     */
    private function existenciaBloqueada(int $materialId, Ubicacion $ubicacion, bool $crear): ?Existencia
    {
        if ($crear) {
            Existencia::firstOrCreate(
                array_merge(['material_id' => $materialId], $ubicacion->atributosExistencia()),
                ['cantidad' => '0', 'valor_total' => '0'],
            );
        }

        $query = Existencia::query()->where('material_id', $materialId);

        foreach ($ubicacion->atributosExistencia() as $columna => $valor) {
            $valor === null ? $query->whereNull($columna) : $query->where($columna, $valor);
        }

        return $query->lockForUpdate()->first();
    }

    /**
     * Valor proporcional que se retira al sacar `cantidad` de una ubicación
     * con `valorTotal` y `cantidadActual`. Mantiene el promedio invariante.
     */
    private function valorProporcional(string $valorTotal, string $cantidadActual, string $cantidad): string
    {
        // valorTotal × cantidad / cantidadActual
        $producto = bcmul($valorTotal, $cantidad, self::SCALE_INTERNO);
        $crudo = bcdiv($producto, $cantidadActual, self::SCALE_INTERNO);

        return $this->bcround($crudo, self::SCALE_MONTO);
    }

    /**
     * Redondeo half-up a la escala dada (idéntico patrón al calculador de
     * fichas). Solo se aplica al persistir/exponer, nunca en intermedios.
     */
    private function bcround(string $value, int $scale): string
    {
        $factor = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', self::SCALE_INTERNO) >= 0) {
            return bcadd($value, $factor, $scale);
        }

        return bcsub($value, $factor, $scale);
    }
}
