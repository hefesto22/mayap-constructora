<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nivel de alerta de un plan de mantenimiento preventivo — derivado,
 * nunca se guarda: se calcula comparando el uso desde el último cambio
 * contra el intervalo del plan (horas, km y/o días — el peor manda).
 *
 * Umbral PRÓXIMO: 90% del intervalo consumido. VENCIDO: 100% o más.
 */
enum AlertaMantenimiento: string
{
    case AlDia = 'al_dia';
    case Proximo = 'proximo';
    case Vencido = 'vencido';

    /**
     * Para comparar severidades (el badge del listado muestra el peor
     * plan de la máquina).
     */
    public function severidad(): int
    {
        return match ($this) {
            self::AlDia   => 0,
            self::Proximo => 1,
            self::Vencido => 2,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::AlDia   => 'Al día',
            self::Proximo => 'Próximo',
            self::Vencido => 'Vencido',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AlDia   => 'success',
            self::Proximo => 'warning',
            self::Vencido => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::AlDia   => 'heroicon-o-check-circle',
            self::Proximo => 'heroicon-o-clock',
            self::Vencido => 'heroicon-o-exclamation-triangle',
        };
    }
}
