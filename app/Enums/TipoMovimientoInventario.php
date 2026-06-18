<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Tipos de movimiento en el libro mayor de inventario (`movimientos_inventario`).
 *
 * Cada movimiento es una entrada inmutable que afecta una o dos ubicaciones
 * (bodega física o proyecto). El tipo determina la semántica de origen/destino:
 *
 * - EntradaCompra:  proveedor → bodega.    Define el costo que alimenta el WAC.
 * - SalidaDespacho: bodega → obra.         Imputa costo al proyecto (ADR-0002 §2).
 * - Traslado:       ubicación → ubicación. Mueve stock sin generar costo nuevo.
 * - ConsumoObra:    baja en obra.          Gasto físico día a día (trazabilidad).
 * - Devolucion:     obra → bodega.         Material no usado que regresa.
 * - AjustePositivo: alta sin origen.       Conteo físico, hallazgo.
 * - AjusteNegativo: baja sin destino.      Merma, daño, contingencia (motivo obligatorio).
 *
 * El costeo (promedio ponderado móvil) recalcula el costo unitario SOLO en
 * EntradaCompra y AjustePositivo. Las salidas usan el promedio vigente.
 *
 * Los CHECK constraints de `movimientos_inventario` validan que el valor
 * siempre esté en este conjunto.
 */
enum TipoMovimientoInventario: string implements HasColor, HasIcon, HasLabel
{
    case EntradaCompra = 'entrada_compra';
    case SalidaDespacho = 'salida_despacho';
    case Traslado = 'traslado';
    case ConsumoObra = 'consumo_obra';
    case Devolucion = 'devolucion';
    case AjustePositivo = 'ajuste_positivo';
    case AjusteNegativo = 'ajuste_negativo';

    public function getLabel(): string
    {
        return match ($this) {
            self::EntradaCompra  => 'Entrada por compra',
            self::SalidaDespacho => 'Salida / despacho a obra',
            self::Traslado       => 'Traslado entre ubicaciones',
            self::ConsumoObra    => 'Consumo en obra',
            self::Devolucion     => 'Devolución a bodega',
            self::AjustePositivo => 'Ajuste positivo',
            self::AjusteNegativo => 'Ajuste negativo / merma',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EntradaCompra, self::AjustePositivo => 'success',
            self::SalidaDespacho, self::ConsumoObra   => 'info',
            self::Traslado                            => 'gray',
            self::Devolucion                          => 'warning',
            self::AjusteNegativo                      => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::EntradaCompra  => 'heroicon-o-arrow-down-tray',
            self::SalidaDespacho => 'heroicon-o-truck',
            self::Traslado       => 'heroicon-o-arrows-right-left',
            self::ConsumoObra    => 'heroicon-o-fire',
            self::Devolucion     => 'heroicon-o-arrow-uturn-left',
            self::AjustePositivo => 'heroicon-o-plus-circle',
            self::AjusteNegativo => 'heroicon-o-minus-circle',
        };
    }

    /**
     * ¿Este tipo descuenta stock de una ubicación de ORIGEN?
     *
     * Las entradas (compra, ajuste positivo) no tienen origen — el stock
     * aparece. Todo lo demás sale de algún lado.
     */
    public function tieneOrigen(): bool
    {
        return match ($this) {
            self::EntradaCompra, self::AjustePositivo => false,
            default                                   => true,
        };
    }

    /**
     * ¿Este tipo agrega stock a una ubicación de DESTINO?
     *
     * El consumo en obra y el ajuste negativo son bajas puras (no hay
     * destino). El despacho y la devolución SÍ tienen destino: el stock
     * que sale de un lado entra en otro (ej: 100 bolsas despachadas
     * incrementan la existencia de la obra — caso de las 130).
     */
    public function tieneDestino(): bool
    {
        return match ($this) {
            self::ConsumoObra, self::AjusteNegativo => false,
            default                                 => true,
        };
    }

    /**
     * ¿El costo unitario lo define el movimiento (true) o lo hereda del
     * promedio ponderado vigente del origen (false)?
     *
     * Solo las entradas con costo de compra propio (compra, ajuste
     * positivo) definen costo. Los traslados/despachos/devoluciones
     * arrastran el costo promedio del origen — no inventan costo nuevo.
     */
    public function defineCostoPropio(): bool
    {
        return match ($this) {
            self::EntradaCompra, self::AjustePositivo => true,
            default                                   => false,
        };
    }

    /**
     * ¿Exige un motivo escrito obligatorio?
     * Ajustes y mermas necesitan justificación para trazabilidad.
     */
    public function requiereMotivo(): bool
    {
        return match ($this) {
            self::AjustePositivo, self::AjusteNegativo => true,
            default                                    => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $caso): array => [$caso->value => $caso->getLabel()])
            ->all();
    }
}
