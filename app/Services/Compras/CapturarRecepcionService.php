<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoVerificableException;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\User;
use App\Support\Permisos;
use Illuminate\Support\Facades\DB;

/**
 * CAPTURA DE RECEPCIÓN (decisión Mauricio 2026-09-05, extendida a obra
 * el 2026-09-10).
 *
 * "En compras registrar debería ser sencillo... son 50 compras en un
 * solo día." Quien registra ya no escribe el detalle: pone el total de la
 * factura y sube la foto. Acá lo escribe QUIEN RECIBE, con la mercadería
 * enfrente y la foto al lado: el bodeguero si entró a bodega, el
 * encargado si el camión llegó directo a la obra.
 *
 * Por qué las líneas nacen ya VERIFICADAS: quien recibe no está contando
 * contra una lista que otro escribió — está escribiendo la lista MIENTRAS
 * cuenta. Facturado y recibido son el mismo acto, así que no existe la
 * diferencia que el flujo clásico persigue.
 *
 * El cuadre contra el total declarado es la red: si lo capturado no suma
 * lo que dice la factura, no entra al inventario. Sin esa red, la captura
 * diferida sería una puerta para que el stock y la contabilidad se
 * separaran en silencio.
 */
final readonly class CapturarRecepcionService
{
    public function __construct(
        private ConfirmarCompraService $confirmar,
        private AlcanceDestinoCompra $alcance,
    ) {}

    /**
     * @param list<array{material_id: int|string, cantidad: string|float|int, precio_factura: string|float|int, exento?: bool}> $lineas
     */
    public function capturar(Compra $compra, array $lineas, User $receptor): EstadoCompra
    {
        if (! $compra->esperandoCaptura()) {
            throw CompraNoVerificableException::noEsperaCaptura($compra->codigo);
        }

        if ($lineas === []) {
            throw CompraNoVerificableException::sinLineasCapturadas($compra->codigo);
        }

        // Quién puede escribir el detalle es la MISMA regla de siempre:
        // el bodeguero de esa bodega, o el encargado de esa obra. Quien
        // compró no se auto-valida.
        if (! $receptor->can(Permisos::VERIFICAR_RECEPCION_COMPRA)
            || ! $this->alcance->alcanzaDestino($receptor, $compra->destinoDeCabecera())
        ) {
            throw CompraNoVerificableException::sinAlcance($compra->codigo, 'la recepción');
        }

        // Todavía no llegó el día prometido: no hay nada que contar
        // (misma regla que la verificación clásica).
        if ($compra->fecha_estimada_llegada?->gt(today()) === true) {
            throw CompraNoVerificableException::llegadaEnElFuturo(
                $compra->codigo,
                $compra->fecha_estimada_llegada->format('d/m/Y'),
            );
        }

        return DB::transaction(function () use ($compra, $lineas, $receptor): EstadoCompra {
            $bloqueada = Compra::query()->whereKey($compra->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoCompra::PorRecibir) {
                throw CompraNoVerificableException::estadoInvalido($compra->codigo, $bloqueada->estado);
            }

            $vistos = [];
            $ahora = now();

            foreach ($lineas as $fila) {
                $materialId = (int) ($fila['material_id'] ?? 0);
                $cantidad = (string) ($fila['cantidad'] ?? '0');
                $precio = (string) ($fila['precio_factura'] ?? '0');

                if ($materialId === 0 || ! is_numeric($cantidad) || (float) $cantidad <= 0.0) {
                    throw CompraNoVerificableException::cantidadInvalida($cantidad);
                }

                if (isset($vistos[$materialId])) {
                    throw CompraNoVerificableException::materialRepetido(
                        $compra->codigo,
                        (string) $materialId,
                    );
                }

                $vistos[$materialId] = true;

                $cantidadNormalizada = number_format((float) $cantidad, 4, '.', '');
                $precioNormalizado = number_format((float) $precio, 4, '.', '');

                CompraLinea::create([
                    'compra_id'      => $compra->id,
                    'material_id'    => $materialId,
                    'cantidad'       => $cantidadNormalizada,
                    'precio_factura' => $precioNormalizado,
                    // El costo lo re-fija recalcularTotales/confirmar con
                    // el prorrateo de flete y descuento.
                    'costo_unitario' => $precioNormalizado,
                    'subtotal'       => number_format(
                        (float) bcmul($cantidadNormalizada, $precioNormalizado, 4),
                        2,
                        '.',
                        '',
                    ),
                    'exento' => (bool) ($fila['exento'] ?? false),
                    // Escribir la línea ES contarla: no hay una lista
                    // previa contra la cual pueda haber diferencia.
                    'cantidad_recibida' => $cantidadNormalizada,
                    'verificada_at'     => $ahora,
                    'verificada_por'    => $receptor->id,
                ]);
            }

            $compra->load('lineas');
            $this->confirmar->recalcularTotales($compra);

            // La red: lo capturado tiene que sumar lo que dice la factura.
            if (! $compra->refresh()->totalesCoinciden()) {
                throw CompraNoVerificableException::noCuadraConLaFactura(
                    $compra->codigo,
                    number_format((float) $compra->totalDeclarado(), 2),
                    number_format((float) $compra->total_cache, 2),
                );
            }

            $this->confirmar->confirmar($compra, $receptor->id);

            return EstadoCompra::Confirmada;
        });
    }
}
