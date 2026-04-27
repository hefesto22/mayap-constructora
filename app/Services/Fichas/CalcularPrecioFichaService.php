<?php

declare(strict_types=1);

namespace App\Services\Fichas;

use App\Enums\CategoriaBaseLinea;
use App\Enums\CategoriaItem;
use App\Models\Ficha;
use App\Models\FichaLinea;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Calculadora de fichas APU — núcleo matemático de Sprint 2.
 *
 * PHP puro, sin acoplamiento a Filament ni HTTP. Toda la matemática
 * usa bcmath con scale=12 en cálculos intermedios y redondea a 2
 * decimales solo al exponer al exterior. Esto evita errores acumulados
 * de coma flotante y replica el comportamiento de Excel cuando una
 * fórmula muestra valores redondeados pero internamente trabaja con
 * la precisión completa.
 *
 * FÓRMULAS DEL DOMINIO:
 *
 *   tipo='item':
 *     rendimiento_efectivo = rendimiento × (1 + desperdicio/100)
 *     subtotal             = rendimiento_efectivo × precio_unitario_item
 *
 *   tipo='porcentaje':
 *     base     = subtotal_de(categoria_base)   ← valor crudo, no redondeado
 *     subtotal = base × porcentaje/100
 *
 *   Totales de la ficha:
 *     costo_directo  = subtotal_mat + subtotal_mo + subtotal_he      (3 categorías)
 *     subtotal       = costo_directo + subtotal_indirectos           (4 categorías)
 *     utilidad_monto = subtotal × utilidad_porcentaje/100
 *     precio_venta   = subtotal + utilidad_monto
 *
 * ORDEN DE CÁLCULO (importante):
 *   1. Calcular líneas tipo `item`. Acumular subtotales crudos por categoría.
 *   2. Calcular líneas tipo `porcentaje` con base puntual (materiales |
 *      mano_obra | herramienta_equipo). Sumar a su categoria_destino.
 *   3. Calcular líneas tipo `porcentaje` con base = costo_directo. Para ese
 *      momento ya están cerradas las 3 categorías directas. Sumar a destino.
 *
 * Las líneas tipo porcentaje cuya categoria_destino sea Indirectos engruesan
 * el subtotal de Indirectos. En el PDF cliente esa sección NO se desglosa —
 * solo se ve el total. Esa lógica vive en el renderizador, no aquí.
 */
final class CalcularPrecioFichaService
{
    /** Scale interno para cálculos intermedios — 12 decimales. */
    private const int SCALE_INTERNO = 12;

    /** Scale final para presentación — 2 decimales (centavos HNL). */
    private const int SCALE_FINAL = 2;

    /**
     * Calcula subtotal de una línea tipo `item`. Resultado redondeado
     * a 2 decimales — pensado para mostrar al usuario.
     *
     * @param string $rendimientoBase Ej: "0.850000"
     * @param string $desperdicioPorcentaje Ej: "5.00"
     * @param string $precioUnitarioItem Ej: "220.00"
     */
    public function calcularLineaItem(
        string $rendimientoBase,
        string $desperdicioPorcentaje,
        string $precioUnitarioItem,
    ): string {
        return $this->bcround(
            $this->calcularLineaItemCrudo($rendimientoBase, $desperdicioPorcentaje, $precioUnitarioItem),
            self::SCALE_FINAL,
        );
    }

    /**
     * Calcula subtotal de una línea tipo `porcentaje`. Resultado redondeado.
     */
    public function calcularLineaPorcentaje(string $porcentaje, string $base): string
    {
        return $this->bcround(
            $this->calcularLineaPorcentajeCrudo($porcentaje, $base),
            self::SCALE_FINAL,
        );
    }

    /**
     * Calcula el rendimiento efectivo de una línea tipo `item`.
     * Útil para mostrar en el form/PDF el rendimiento "real" usado.
     */
    public function rendimientoEfectivo(string $rendimientoBase, string $desperdicioPorcentaje): string
    {
        $factorDesperdicio = bcdiv($desperdicioPorcentaje, '100', self::SCALE_INTERNO);
        $multiplicador = bcadd('1', $factorDesperdicio, self::SCALE_INTERNO);

        return $this->bcround(
            bcmul($rendimientoBase, $multiplicador, self::SCALE_INTERNO),
            6,
        );
    }

    /**
     * Calcula el desglose completo de una ficha.
     *
     * Carga las líneas con sus relaciones necesarias en una sola pasada
     * para evitar N+1.
     */
    public function calcular(Ficha $ficha): ResultadoCalculoFicha
    {
        $ficha->loadMissing(['lineas.item.unidadMedida']);

        // Subtotales CRUDOS por categoría (scale=12, no redondeados).
        $subtotalesCrudos = [
            CategoriaItem::Materiales->value        => '0',
            CategoriaItem::ManoObra->value          => '0',
            CategoriaItem::HerramientaEquipo->value => '0',
            CategoriaItem::Indirectos->value        => '0',
        ];

        $detalles = [];

        // ─── Pasada 1: líneas tipo `item` ──────────────────────────
        foreach ($ficha->lineas as $linea) {
            if (! $linea->esItem()) {
                continue;
            }

            [$detalle, $subtotalCrudo] = $this->calcularDetalleItem($linea);
            $detalles[] = $detalle;

            $subtotalesCrudos[$detalle->seccion->value] = bcadd(
                $subtotalesCrudos[$detalle->seccion->value],
                $subtotalCrudo,
                self::SCALE_INTERNO,
            );
        }

        // ─── Pasada 2: porcentajes con base puntual ────────────────
        foreach ($ficha->lineas as $linea) {
            if (! $linea->esPorcentaje()) {
                continue;
            }

            if ($linea->categoria_base?->esAgregada()) {
                continue;
            }

            [$detalle, $subtotalCrudo] = $this->calcularDetallePorcentaje($linea, $subtotalesCrudos);
            $detalles[] = $detalle;

            $subtotalesCrudos[$detalle->seccion->value] = bcadd(
                $subtotalesCrudos[$detalle->seccion->value],
                $subtotalCrudo,
                self::SCALE_INTERNO,
            );
        }

        // ─── Pasada 3: porcentajes sobre costo_directo ─────────────
        // En este punto las 3 categorías directas (mat/mo/he) ya están
        // cerradas. Indirectos puede recibir más aportes en esta pasada.
        foreach ($ficha->lineas as $linea) {
            if (! $linea->esPorcentaje()) {
                continue;
            }

            if (! $linea->categoria_base?->esAgregada()) {
                continue;
            }

            [$detalle, $subtotalCrudo] = $this->calcularDetallePorcentaje($linea, $subtotalesCrudos);
            $detalles[] = $detalle;

            $subtotalesCrudos[$detalle->seccion->value] = bcadd(
                $subtotalesCrudos[$detalle->seccion->value],
                $subtotalCrudo,
                self::SCALE_INTERNO,
            );
        }

        // ─── Totales finales (sobre crudos, redondeo solo al exponer) ───
        $costoDirectoCrudo = $this->sumarCostoDirectoCrudo($subtotalesCrudos);
        $subtotalCrudo = bcadd(
            $costoDirectoCrudo,
            $subtotalesCrudos[CategoriaItem::Indirectos->value],
            self::SCALE_INTERNO,
        );

        $utilidadPorcentaje = (string) $ficha->utilidad_porcentaje;
        $utilidadCrudo = $this->calcularLineaPorcentajeCrudo($utilidadPorcentaje, $subtotalCrudo);
        $precioVentaCrudo = bcadd($subtotalCrudo, $utilidadCrudo, self::SCALE_INTERNO);

        // ─── Subtotales por categoría redondeados (presentación) ───
        $subtotalesPresentacion = [];

        foreach ($subtotalesCrudos as $cat => $valor) {
            $subtotalesPresentacion[$cat] = $this->bcround($valor, self::SCALE_FINAL);
        }

        return new ResultadoCalculoFicha(
            subtotalesPorCategoria: $subtotalesPresentacion,
            detallesPorLinea: $detalles,
            costoDirecto: $this->bcround($costoDirectoCrudo, self::SCALE_FINAL),
            subtotal: $this->bcround($subtotalCrudo, self::SCALE_FINAL),
            utilidadPorcentaje: $this->bcround($utilidadPorcentaje, self::SCALE_FINAL),
            utilidadMonto: $this->bcround($utilidadCrudo, self::SCALE_FINAL),
            precioVenta: $this->bcround($precioVentaCrudo, self::SCALE_FINAL),
        );
    }

    /**
     * Recalcula la ficha y persiste el cache de cálculo en la fila.
     *
     * Marca `precio_calculado_at = now()`. Si después de esta llamada
     * cambian precios de items referenciados, la ficha quedará con
     * cache stale; el scope `cacheDesactualizado` y el indicador del
     * listado lo señalan visualmente.
     *
     * Va dentro de transacción para que update + asignación de
     * timestamp sean atómicos respecto a otras escrituras.
     */
    public function recalcularYPersistir(Ficha $ficha): ResultadoCalculoFicha
    {
        return DB::transaction(function () use ($ficha): ResultadoCalculoFicha {
            $resultado = $this->calcular($ficha);

            $ficha->forceFill([
                'subtotal_cache'      => $resultado->subtotal,
                'precio_venta_cache'  => $resultado->precioVenta,
                'precio_calculado_at' => now(),
            ])->save();

            return $resultado;
        });
    }

    // ─── Cálculos crudos (internos, no redondeados) ────────────────

    private function calcularLineaItemCrudo(
        string $rendimientoBase,
        string $desperdicioPorcentaje,
        string $precioUnitarioItem,
    ): string {
        $factorDesperdicio = bcdiv($desperdicioPorcentaje, '100', self::SCALE_INTERNO);
        $multiplicador = bcadd('1', $factorDesperdicio, self::SCALE_INTERNO);
        $rendimientoEfectivo = bcmul($rendimientoBase, $multiplicador, self::SCALE_INTERNO);

        return bcmul($rendimientoEfectivo, $precioUnitarioItem, self::SCALE_INTERNO);
    }

    private function calcularLineaPorcentajeCrudo(string $porcentaje, string $base): string
    {
        $factor = bcdiv($porcentaje, '100', self::SCALE_INTERNO);

        return bcmul($base, $factor, self::SCALE_INTERNO);
    }

    /**
     * @param array<value-of<CategoriaItem>, string> $subtotalesCrudos
     */
    private function sumarCostoDirectoCrudo(array $subtotalesCrudos): string
    {
        return bcadd(
            bcadd(
                $subtotalesCrudos[CategoriaItem::Materiales->value],
                $subtotalesCrudos[CategoriaItem::ManoObra->value],
                self::SCALE_INTERNO,
            ),
            $subtotalesCrudos[CategoriaItem::HerramientaEquipo->value],
            self::SCALE_INTERNO,
        );
    }

    // ─── Helpers de detalle por línea ──────────────────────────────

    /**
     * @return array{0: DetalleLineaCalculada, 1: string} [detalle, subtotalCrudo]
     */
    private function calcularDetalleItem(FichaLinea $linea): array
    {
        $item = $linea->item;

        if ($item === null) {
            // Defensa: el CHECK constraint de DB previene esto, pero
            // lo cubrimos para PHPStan y para tests con mocks parciales.
            throw new DomainException(
                "FichaLinea #{$linea->id} es tipo=item pero no tiene item asociado."
            );
        }

        $rendimiento = (string) $linea->rendimiento;
        $desperdicio = (string) ($linea->desperdicio_porcentaje ?? '0.00');
        $precio = (string) $item->precio_unitario;

        $subtotalCrudo = $this->calcularLineaItemCrudo($rendimiento, $desperdicio, $precio);

        $detalle = new DetalleLineaCalculada(
            lineaId: $linea->id,
            seccion: $item->categoria,
            descripcion: $item->nombre,
            unidad: $item->unidadMedida->codigo,
            rendimientoEfectivo: $this->rendimientoEfectivo($rendimiento, $desperdicio),
            precioUnitario: $precio,
            subtotal: $this->bcround($subtotalCrudo, self::SCALE_FINAL),
        );

        return [$detalle, $subtotalCrudo];
    }

    /**
     * @param array<value-of<CategoriaItem>, string> $subtotalesCrudos
     *
     * @return array{0: DetalleLineaCalculada, 1: string} [detalle, subtotalCrudo]
     */
    private function calcularDetallePorcentaje(FichaLinea $linea, array $subtotalesCrudos): array
    {
        $baseCruda = $this->resolverBaseCruda($linea->categoria_base, $subtotalesCrudos);
        $porcentaje = (string) $linea->porcentaje;
        $subtotalCrudo = $this->calcularLineaPorcentajeCrudo($porcentaje, $baseCruda);

        $detalle = new DetalleLineaCalculada(
            lineaId: $linea->id,
            seccion: $linea->categoria_destino ?? CategoriaItem::Indirectos,
            descripcion: $linea->descripcion ?? 'Renglón derivado',
            unidad: '%',
            rendimientoEfectivo: '',
            precioUnitario: $this->bcround($baseCruda, self::SCALE_FINAL),
            subtotal: $this->bcround($subtotalCrudo, self::SCALE_FINAL),
            esPorcentaje: true,
            porcentajeAplicado: $porcentaje,
            baseDelPorcentaje: $this->bcround($baseCruda, self::SCALE_FINAL),
        );

        return [$detalle, $subtotalCrudo];
    }

    /**
     * Resuelve la base cruda (no redondeada) sobre la que aplica el porcentaje.
     *
     * @param array<value-of<CategoriaItem>, string> $subtotalesCrudos
     */
    private function resolverBaseCruda(?CategoriaBaseLinea $base, array $subtotalesCrudos): string
    {
        return match ($base) {
            CategoriaBaseLinea::Materiales        => $subtotalesCrudos[CategoriaItem::Materiales->value],
            CategoriaBaseLinea::ManoObra          => $subtotalesCrudos[CategoriaItem::ManoObra->value],
            CategoriaBaseLinea::HerramientaEquipo => $subtotalesCrudos[CategoriaItem::HerramientaEquipo->value],
            CategoriaBaseLinea::CostoDirecto      => $this->sumarCostoDirectoCrudo($subtotalesCrudos),
            null                                  => '0',
        };
    }

    /**
     * Redondeo half-away-from-zero con bcmath. bcadd no redondea, trunca;
     * sumando un factor 0.5 en la última posición antes de truncar
     * emulamos el modo de redondeo estándar (igual a round() de PHP).
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
