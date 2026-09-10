<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estados de una requisición de material en su ciclo de vida — la columna
 * vertebral del sistema (ver docs/arquitectura/sistema-completo.md §3).
 *
 * Workflow con responsable registrado en cada transición:
 *
 *   Solicitada ─(autoriza)─> Autorizada ─(hay stock)──> Despachada
 *       │                        │                    (origen: bodega)
 *       │                        │(no hay stock)            │
 *       │(rechaza)               ▼                          ▼
 *       ▼                  RequisicionCompra             EnTransito
 *   Rechazada              (notifica compras)               │
 *                                │                          ▼
 *                                │(el proveedor entrega  Recibida
 *                                │ directo en la obra)      │
 *                                ▼                     ┌────┴─────┐
 *                            Despachada ─────────► (cuadra)   (no cuadra)
 *                     (origen: compra_directa)         ▼            ▼
 *                      SIN pasar por tránsito       Cerrada    Discrepancia
 *
 * Reglas de negocio:
 *  - Solo `Solicitada` permite editar las líneas (cantidades, items).
 *  - `EnTransito` SOLO existe en la vía bodega. Una compra directa a obra
 *    no tiene tramo de tránsito nuestro: el material lo dejó el proveedor
 *    en el sitio. Por eso `transicionesPermitidas()` recibe el
 *    OrigenDespacho de la requisición (decisión Mauricio 2026-08-07, bug
 *    de REQ-2026-00005: se despachó por compra directa y aun así el
 *    sistema ofreció "Marcar en tránsito" — y alguien lo apretó).
 *  - `RequisicionCompra` es un estado INTERNO: falta comprar algo de lo
 *    que la obra pidió, y se notifica a Administración. Cuando el stock
 *    entra (vía RegistrarMovimientoService::entradaCompra) se puede
 *    Despachar. NO se acopla todavía al módulo de Compras (Fase B).
 *  - Desde 2026-09-10 el estado de la CABECERA se deduce de lo que se
 *    decidió en cada renglón (ver ResolucionLinea y
 *    TransicionarRequisicionService::resolverDisponibilidad): queda algo
 *    por llegar → RequisicionCompra; nada por llegar pero algo salió →
 *    Despachada; nada por llegar y nada salió → Rechazada. Antes bastaba
 *    UN material sin stock para frenar el pedido completo, y la obra
 *    esperaba el cemento que sí había por culpa de unos clavos que no.
 *  - El despacho mueve stock real bodega→obra valorado con el WAC.
 *  - En la recepción se compara cantidad_despachada vs cantidad_recibida:
 *    si cuadran → Cerrada; si no → Discrepancia (se sabe dónde y quién).
 *  - `Cerrada`, `Discrepancia` y `Rechazada` son terminales.
 *
 * Los CHECK constraints de la tabla `requisiciones` validan que el estado
 * siempre esté dentro de este conjunto.
 */
enum EstadoRequisicion: string implements HasColor, HasIcon, HasLabel
{
    case Solicitada = 'solicitada';
    case Autorizada = 'autorizada';
    case RequisicionCompra = 'requisicion_compra';
    case Despachada = 'despachada';
    case EnTransito = 'en_transito';
    case Recibida = 'recibida';
    case Cerrada = 'cerrada';
    case Discrepancia = 'discrepancia';
    case Rechazada = 'rechazada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Solicitada        => 'Solicitada',
            self::Autorizada        => 'Autorizada',
            self::RequisicionCompra => 'Requisición de compra',
            self::Despachada        => 'Despachada',
            self::EnTransito        => 'En tránsito',
            self::Recibida          => 'Recibida',
            self::Cerrada           => 'Cerrada',
            self::Discrepancia      => 'Discrepancia',
            self::Rechazada         => 'Rechazada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Solicitada        => 'gray',
            self::Autorizada        => 'info',
            self::RequisicionCompra => 'warning',
            self::Despachada        => 'info',
            self::EnTransito        => 'info',
            self::Recibida          => 'primary',
            self::Cerrada           => 'success',
            self::Discrepancia      => 'danger',
            self::Rechazada         => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Solicitada        => 'heroicon-o-paper-airplane',
            self::Autorizada        => 'heroicon-o-check-circle',
            self::RequisicionCompra => 'heroicon-o-shopping-cart',
            self::Despachada        => 'heroicon-o-truck',
            self::EnTransito        => 'heroicon-o-arrow-right-circle',
            self::Recibida          => 'heroicon-o-inbox-arrow-down',
            self::Cerrada           => 'heroicon-o-check-badge',
            self::Discrepancia      => 'heroicon-o-exclamation-triangle',
            self::Rechazada         => 'heroicon-o-x-circle',
        };
    }

    /**
     * Estados a los que se puede transicionar desde el actual.
     *
     * El ÚNICO renglón que depende del origen es `Despachada`: por bodega
     * sigue el tránsito; por compra directa el material ya está en la obra
     * y lo único que falta es que la obra confirme la recepción. Sin origen
     * (null) se asume la vía bodega — nunca nos saltamos un tránsito real.
     *
     * @return array<int, self>
     */
    public function transicionesPermitidas(?OrigenDespacho $origen = null): array
    {
        return match ($this) {
            self::Solicitada        => [self::Autorizada, self::Rechazada],
            self::Autorizada        => [self::Despachada, self::RequisicionCompra, self::Rechazada],
            self::RequisicionCompra => [self::Despachada, self::Rechazada],
            self::Despachada        => $origen?->esCompraDirecta() === true
                ? [self::Recibida]
                : [self::EnTransito],
            self::EnTransito => [self::Recibida],
            self::Recibida   => [self::Cerrada, self::Discrepancia],
            // Terminales.
            self::Cerrada,
            self::Discrepancia,
            self::Rechazada => [],
        };
    }

    /**
     * ¿Se puede transicionar de este estado al dado?
     */
    public function puedeTransicionarA(self $destino, ?OrigenDespacho $origen = null): bool
    {
        return in_array($destino, $this->transicionesPermitidas($origen), strict: true);
    }

    /**
     * ¿Es un estado terminal (sin transiciones de salida)?
     */
    public function esTerminal(): bool
    {
        return in_array($this, [self::Cerrada, self::Discrepancia, self::Rechazada], strict: true);
    }

    /**
     * ¿Permite editar las líneas (items, cantidades) de la requisición?
     * Solo mientras está en Solicitada.
     */
    public function permiteEditarLineas(): bool
    {
        return $this === self::Solicitada;
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
