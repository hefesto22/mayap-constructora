<?php

declare(strict_types=1);

namespace App\Services\Fichas;

use App\Enums\CategoriaBaseLinea;
use App\Enums\CategoriaItem;
use App\Enums\TipoLineaFicha;
use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * Calculadora desde el state del form de Filament — para el cálculo
 * EN VIVO mientras el ingeniero teclea, sin necesidad de guardar.
 *
 * Diferencia con CalcularPrecioFichaService:
 *  - El service base trabaja con instancias persistidas de Ficha/FichaLinea.
 *  - Este service trabaja con arrays planos del state del form de Filament,
 *    que aún NO están persistidos.
 *
 * Internamente reutiliza CalcularPrecioFichaService para no duplicar la
 * matemática. Solo encarga la traducción state→cálculo.
 *
 * Optimización: hace UNA sola query para cargar todos los items
 * referenciados, evitando N+1 cuando el form se redibuja en vivo.
 */
final class CalcularDesdeStateFicha
{
    public function __construct(
        private readonly CalcularPrecioFichaService $servicio = new CalcularPrecioFichaService,
    ) {}

    /**
     * Calcula totales desde el state actual del form.
     *
     * @param array<int, array<string, mixed>> $lineasState El estado del repeater 'lineas'
     *
     * @return array{
     *     subtotalesPorCategoria: array<value-of<CategoriaItem>, string>,
     *     costoDirecto: string,
     *     subtotal: string,
     *     utilidadMonto: string,
     *     precioVenta: string,
     *     detallesPorLinea: array<int, array{
     *         rendimientoEfectivo: string,
     *         subtotal: string,
     *     }>,
     * }
     */
    public function calcular(array $lineasState, string|float|int|null $utilidadPorcentaje): array
    {
        $items = $this->cargarItems($lineasState);

        $subtotalesCrudos = [
            CategoriaItem::Materiales->value        => '0',
            CategoriaItem::ManoObra->value          => '0',
            CategoriaItem::HerramientaEquipo->value => '0',
            CategoriaItem::Indirectos->value        => '0',
        ];

        $detallesPorLinea = [];

        // ─── Pasada 1: líneas tipo `item` ──────────────────────────
        foreach ($lineasState as $idx => $linea) {
            if (($linea['tipo'] ?? null) !== TipoLineaFicha::Item->value) {
                continue;
            }

            $itemId = $linea['item_id'] ?? null;
            $item = $itemId !== null ? ($items[(int) $itemId] ?? null) : null;

            if ($item === null) {
                $detallesPorLinea[$idx] = [
                    'rendimientoEfectivo' => '0.000000',
                    'subtotal'            => '0.00',
                ];

                continue;
            }

            $rendimiento = (string) ($linea['rendimiento'] ?? '0');
            $desperdicio = (string) ($linea['desperdicio_porcentaje'] ?? '0');
            $precio = (string) $item->precio_unitario;

            $subtotal = $this->servicio->calcularLineaItem($rendimiento, $desperdicio, $precio);

            // El rendimiento ya es efectivo (modelo único Sprint 2 Sesión 3).
            // Se muestra tal cual lo escribió el ingeniero.
            $detallesPorLinea[$idx] = [
                'rendimientoEfectivo' => $rendimiento,
                'subtotal'            => $subtotal,
            ];

            $cat = $item->categoria->value;
            $subtotalesCrudos[$cat] = bcadd($subtotalesCrudos[$cat], $subtotal, 12);
        }

        // ─── Pasada 2: porcentajes con base puntual ────────────────
        foreach ($lineasState as $idx => $linea) {
            if (($linea['tipo'] ?? null) !== TipoLineaFicha::Porcentaje->value) {
                continue;
            }

            $base = $this->resolverBaseLinea($linea, $subtotalesCrudos);

            if ($base === null) {
                continue; // base CostoDirecto, lo procesamos en pasada 3
            }

            $resultado = $this->procesarPorcentaje($linea, $base, $subtotalesCrudos);
            $detallesPorLinea[$idx] = $resultado['detalle'];
            $subtotalesCrudos = $resultado['subtotales'];
        }

        // ─── Pasada 3: porcentajes sobre costo_directo ─────────────
        foreach ($lineasState as $idx => $linea) {
            if (($linea['tipo'] ?? null) !== TipoLineaFicha::Porcentaje->value) {
                continue;
            }

            if (($linea['categoria_base'] ?? null) !== CategoriaBaseLinea::CostoDirecto->value) {
                continue;
            }

            $base = bcadd(
                bcadd(
                    $subtotalesCrudos[CategoriaItem::Materiales->value],
                    $subtotalesCrudos[CategoriaItem::ManoObra->value],
                    12,
                ),
                $subtotalesCrudos[CategoriaItem::HerramientaEquipo->value],
                12,
            );

            $resultado = $this->procesarPorcentaje($linea, $base, $subtotalesCrudos);
            $detallesPorLinea[$idx] = $resultado['detalle'];
            $subtotalesCrudos = $resultado['subtotales'];
        }

        // ─── Totales finales ───────────────────────────────────────
        $costoDirectoCrudo = bcadd(
            bcadd(
                $subtotalesCrudos[CategoriaItem::Materiales->value],
                $subtotalesCrudos[CategoriaItem::ManoObra->value],
                12,
            ),
            $subtotalesCrudos[CategoriaItem::HerramientaEquipo->value],
            12,
        );

        $subtotalCrudo = bcadd(
            $costoDirectoCrudo,
            $subtotalesCrudos[CategoriaItem::Indirectos->value],
            12,
        );

        $util = (string) ($utilidadPorcentaje ?? '0');
        $utilidadCrudo = bcdiv(bcmul($subtotalCrudo, $util, 12), '100', 12);
        $precioVentaCrudo = bcadd($subtotalCrudo, $utilidadCrudo, 12);

        return [
            'subtotalesPorCategoria' => [
                CategoriaItem::Materiales->value        => $this->bcround($subtotalesCrudos[CategoriaItem::Materiales->value]),
                CategoriaItem::ManoObra->value          => $this->bcround($subtotalesCrudos[CategoriaItem::ManoObra->value]),
                CategoriaItem::HerramientaEquipo->value => $this->bcround($subtotalesCrudos[CategoriaItem::HerramientaEquipo->value]),
                CategoriaItem::Indirectos->value        => $this->bcround($subtotalesCrudos[CategoriaItem::Indirectos->value]),
            ],
            'costoDirecto'     => $this->bcround($costoDirectoCrudo),
            'subtotal'         => $this->bcround($subtotalCrudo),
            'utilidadMonto'    => $this->bcround($utilidadCrudo),
            'precioVenta'      => $this->bcround($precioVentaCrudo),
            'detallesPorLinea' => $detallesPorLinea,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lineasState
     *
     * @return Collection<int, Item>
     */
    private function cargarItems(array $lineasState): Collection
    {
        $itemIds = collect($lineasState)
            ->filter(fn (array $l): bool => ($l['tipo'] ?? null) === TipoLineaFicha::Item->value)
            ->pluck('item_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        if ($itemIds === []) {
            /** @var Collection<int, Item> $vacia */
            $vacia = collect();

            return $vacia;
        }

        return Item::query()
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * Resuelve la base cruda de una línea tipo % puntual.
     * Retorna null si la base es CostoDirecto (se procesa en pasada 3).
     *
     * @param array<string, mixed> $linea
     * @param array<value-of<CategoriaItem>, string> $subtotales
     */
    private function resolverBaseLinea(array $linea, array $subtotales): ?string
    {
        $base = $linea['categoria_base'] ?? null;

        return match ($base) {
            CategoriaBaseLinea::Materiales->value        => $subtotales[CategoriaItem::Materiales->value],
            CategoriaBaseLinea::ManoObra->value          => $subtotales[CategoriaItem::ManoObra->value],
            CategoriaBaseLinea::HerramientaEquipo->value => $subtotales[CategoriaItem::HerramientaEquipo->value],
            default                                      => null,
        };
    }

    /**
     * @param array<string, mixed> $linea
     * @param array<value-of<CategoriaItem>, string> $subtotales
     *
     * @return array{
     *     detalle: array{rendimientoEfectivo: string, subtotal: string},
     *     subtotales: array<value-of<CategoriaItem>, string>,
     * }
     */
    private function procesarPorcentaje(array $linea, string $base, array $subtotales): array
    {
        $porcentaje = (string) ($linea['porcentaje'] ?? '0');
        $subtotal = $this->servicio->calcularLineaPorcentaje($porcentaje, $base);
        $destino = $linea['categoria_destino'] ?? CategoriaItem::Indirectos->value;

        $subtotalesNuevo = $subtotales;

        if (isset($subtotalesNuevo[$destino])) {
            $subtotalCrudo = bcdiv(bcmul($base, $porcentaje, 12), '100', 12);
            $subtotalesNuevo[$destino] = bcadd($subtotalesNuevo[$destino], $subtotalCrudo, 12);
        }

        return [
            'detalle' => [
                'rendimientoEfectivo' => '',
                'subtotal'            => $subtotal,
            ],
            'subtotales' => $subtotalesNuevo,
        ];
    }

    private function bcround(string $value, int $scale = 2): string
    {
        $factor = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', 12) >= 0) {
            return bcadd($value, $factor, $scale);
        }

        return bcsub($value, $factor, $scale);
    }
}
