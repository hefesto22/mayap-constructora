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
 *       │                        │                          │
 *       │                        │(no hay stock)            ▼
 *       │(rechaza)               ▼                       EnTransito
 *       ▼                  RequisicionCompra                │
 *   Rechazada              (notifica admin)                 ▼
 *                                │                       Recibida
 *                                │(entra stock,             │
 *                                │ se despacha)        ┌────┴─────┐
 *                                ▼               (cuadra)      (no cuadra)
 *                            Despachada            ▼               ▼
 *                                                Cerrada       Discrepancia
 *
 * Reglas de negocio:
 *  - Solo `Solicitada` permite editar las líneas (cantidades, items).
 *  - `RequisicionCompra` es un estado INTERNO: la bodega no tenía stock,
 *    se notifica a Administración. Cuando el stock entra (vía
 *    RegistrarMovimientoService::entradaCompra) se puede Despachar.
 *    NO se acopla todavía al módulo de Compras (Fase B).
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
     * @return array<int, self>
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::Solicitada        => [self::Autorizada, self::Rechazada],
            self::Autorizada        => [self::Despachada, self::RequisicionCompra, self::Rechazada],
            self::RequisicionCompra => [self::Despachada, self::Rechazada],
            self::Despachada        => [self::EnTransito],
            self::EnTransito        => [self::Recibida],
            self::Recibida          => [self::Cerrada, self::Discrepancia],
            // Terminales.
            self::Cerrada,
            self::Discrepancia,
            self::Rechazada => [],
        };
    }

    /**
     * ¿Se puede transicionar de este estado al dado?
     */
    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesPermitidas(), strict: true);
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
