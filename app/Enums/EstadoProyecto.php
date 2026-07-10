<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estados del ciclo de vida completo de un proyecto.
 *
 * Cubre DOS fases:
 *
 *  Fase comercial (cotización):
 *
 *    Borrador ──(enviar)──> Enviada ──(cliente acepta)──> Aprobada
 *                               │
 *                               ├─(cliente rechaza)──> Rechazada
 *                               └─(vence sin respuesta)──> Vencida
 *
 *  Fase de ejecución (obra):
 *
 *    Aprobada ──(iniciar obra)──> EnEjecucion
 *                                     │  ▲
 *                       (pausar) ─────┤  └───── (reactivar)
 *                                     ▼
 *                                  Pausada
 *
 *    EnEjecucion / Pausada ──(finalizar)──> Finalizada
 *    EnEjecucion / Pausada / Aprobada ──(cancelar)──> Cancelada
 *
 * Reglas de negocio:
 *  - Solo `Borrador` permite editar renglones (composición) libremente.
 *  - `Pausada` y `Cancelada` EXIGEN un motivo (se anota en el log).
 *  - `EnEjecucion`, `Pausada` y `Finalizada` requieren `fecha_inicio`
 *    (la obra ya arrancó). Validado por CHECK constraint en la tabla.
 *  - Terminales (solo lectura, histórico): `Rechazada`, `Vencida`,
 *    `Finalizada`, `Cancelada`.
 *  - `Vencida` la asigna el Job diario cuando fecha_validez < today
 *    AND estado = enviada.
 *
 * Los CHECK constraints de la tabla `proyectos` validan que el valor
 * del estado siempre esté en este conjunto.
 */
enum EstadoProyecto: string implements HasColor, HasIcon, HasLabel
{
    // Fase comercial.
    case Borrador = 'borrador';
    case Enviada = 'enviada';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
    case Vencida = 'vencida';

    // Fase de ejecución.
    case EnEjecucion = 'en_ejecucion';
    case Pausada = 'pausada';
    case Finalizada = 'finalizada';
    case Cancelada = 'cancelada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Borrador    => 'Borrador',
            self::Enviada     => 'Enviada al cliente',
            self::Aprobada    => 'Aprobada',
            self::Rechazada   => 'Rechazada',
            self::Vencida     => 'Vencida',
            self::EnEjecucion => 'En ejecución',
            self::Pausada     => 'Pausada',
            self::Finalizada  => 'Finalizada',
            self::Cancelada   => 'Cancelada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Borrador    => 'gray',
            self::Enviada     => 'info',
            self::Aprobada    => 'success',
            self::Rechazada   => 'danger',
            self::Vencida     => 'warning',
            self::EnEjecucion => 'primary',
            self::Pausada     => 'warning',
            self::Finalizada  => 'success',
            self::Cancelada   => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Borrador    => 'heroicon-o-pencil-square',
            self::Enviada     => 'heroicon-o-paper-airplane',
            self::Aprobada    => 'heroicon-o-check-badge',
            self::Rechazada   => 'heroicon-o-x-circle',
            self::Vencida     => 'heroicon-o-clock',
            self::EnEjecucion => 'heroicon-o-play',
            self::Pausada     => 'heroicon-o-pause',
            self::Finalizada  => 'heroicon-o-check-circle',
            self::Cancelada   => 'heroicon-o-no-symbol',
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
        return in_array(
            $this,
            [self::Rechazada, self::Vencida, self::Finalizada, self::Cancelada],
            strict: true,
        );
    }

    /**
     * ¿Es un estado de la fase de ejecución (la obra ya arrancó o terminó)?
     * Se usa para mostrar la pestaña/indicadores de ejecución.
     */
    public function esEjecucion(): bool
    {
        return in_array(
            $this,
            [self::EnEjecucion, self::Pausada, self::Finalizada],
            strict: true,
        );
    }

    /**
     * ¿Requiere fecha_inicio definida? (la obra arrancó).
     * Coincide con el CHECK constraint de la tabla.
     */
    public function requiereFechaInicio(): bool
    {
        return $this->esEjecucion();
    }

    /**
     * ¿La transición HACIA este estado exige capturar un motivo?
     * Pausar y cancelar siempre se justifican.
     */
    public function requiereMotivo(): bool
    {
        return in_array($this, [self::Pausada, self::Cancelada], strict: true);
    }

    /**
     * Estados a los que se puede transicionar desde el actual.
     *
     * @return array<int, self>
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::Borrador => [self::Enviada],
            // Enviada puede volver a Borrador para corregir un error antes
            // de que el cliente responda (queda registrado en el log).
            self::Enviada     => [self::Borrador, self::Aprobada, self::Rechazada, self::Vencida],
            self::Aprobada    => [self::EnEjecucion, self::Cancelada],
            self::EnEjecucion => [self::Pausada, self::Finalizada, self::Cancelada],
            self::Pausada     => [self::EnEjecucion, self::Finalizada, self::Cancelada],
            // Terminales: no hay transiciones.
            self::Rechazada,
            self::Vencida,
            self::Finalizada,
            self::Cancelada => [],
        };
    }

    /**
     * ¿Se puede transicionar del estado actual a $destino?
     */
    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesPermitidas(), strict: true);
    }

    /**
     * Transiciones "simples" — las que NO necesitan datos extra (fecha
     * de inicio, plazo, motivo) y pueden hacerse desde un select básico.
     * Las transiciones de ejecución (iniciar, pausar, cancelar) usan
     * acciones dedicadas con su propio formulario.
     *
     * @return array<int, self>
     */
    public function transicionesSimples(): array
    {
        return array_values(array_filter(
            $this->transicionesPermitidas(),
            static fn (self $estado): bool => ! $estado->requiereFechaInicio() && ! $estado->requiereMotivo(),
        ));
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
