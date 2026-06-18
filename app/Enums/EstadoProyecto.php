<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estados de una cotización/proyecto en su ciclo de vida comercial.
 *
 * Workflow:
 *
 *   Borrador ──(enviar)──> Enviada ──(cliente acepta)──> Aprobada
 *                              │
 *                              ├─(cliente rechaza)──> Rechazada
 *                              │
 *                              └─(vence sin respuesta)──> Vencida
 *
 * Reglas de negocio:
 *  - Solo `Borrador` permite editar renglones libremente.
 *  - `Enviada` solo permite cambio de estado (acepta/rechaza/vence).
 *  - `Aprobada/Rechazada/Vencida` son terminales — solo lectura,
 *    se mantienen como histórico para reportes.
 *  - `Vencida` se asigna automáticamente vía Job programado diario
 *    cuando fecha_validez < today AND estado = enviada.
 *
 * Los CHECK constraints de la tabla `proyectos` validan que el
 * valor del estado siempre esté en este conjunto.
 */
enum EstadoProyecto: string implements HasColor, HasIcon, HasLabel
{
    case Borrador = 'borrador';
    case Enviada = 'enviada';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
    case Vencida = 'vencida';

    public function getLabel(): string
    {
        return match ($this) {
            self::Borrador  => 'Borrador',
            self::Enviada   => 'Enviada al cliente',
            self::Aprobada  => 'Aprobada',
            self::Rechazada => 'Rechazada',
            self::Vencida   => 'Vencida',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Borrador  => 'gray',
            self::Enviada   => 'info',
            self::Aprobada  => 'success',
            self::Rechazada => 'danger',
            self::Vencida   => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Borrador  => 'heroicon-o-pencil-square',
            self::Enviada   => 'heroicon-o-paper-airplane',
            self::Aprobada  => 'heroicon-o-check-badge',
            self::Rechazada => 'heroicon-o-x-circle',
            self::Vencida   => 'heroicon-o-clock',
        };
    }

    /**
     * ¿Permite editar renglones (cantidad, agregar, quitar) en este estado?
     * Solo Borrador es completamente editable.
     */
    public function permiteEditar(): bool
    {
        return $this === self::Borrador;
    }

    /**
     * ¿Es un estado terminal (no se puede cambiar a otro)?
     */
    public function esTerminal(): bool
    {
        return in_array($this, [self::Aprobada, self::Rechazada, self::Vencida], strict: true);
    }

    /**
     * Estados a los que se puede transicionar desde el actual.
     * Usado por el form de cambio de estado para limitar opciones.
     *
     * @return array<int, self>
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::Borrador => [self::Enviada],
            self::Enviada  => [self::Aprobada, self::Rechazada, self::Vencida],
            // Terminales: no hay transiciones.
            self::Aprobada,
            self::Rechazada,
            self::Vencida => [],
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
