<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué pasa con los días AGENDADOS a futuro cuando una máquina se va al
 * taller (decisión Mauricio 2026-07-22 — emergencias desde el calendario):
 *
 *  - Cancelar: el comportamiento clásico — se reagenda al salir del taller.
 *  - Sustituta: otra máquina propia toma la obra y hereda los días.
 *  - ReparacionHoy: la agenda queda EN PIE porque la reparación sale hoy
 *    mismo; si mañana no llega, el ciclo rojo deja la constancia.
 *  - RentaExterna: la agenda queda EN PIE porque se rentará una máquina
 *    de fuera — campanita para gestionar el alquiler y nota en cada día.
 */
enum DestinoAgendaFutura: string
{
    case Cancelar = 'cancelar';
    case Sustituta = 'sustituta';
    case ReparacionHoy = 'reparacion_hoy';
    case RentaExterna = 'renta_externa';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cancelar      => 'Cancelar los días siguientes',
            self::Sustituta     => 'Transferir a una máquina sustituta',
            self::ReparacionHoy => 'Dejar la agenda en pie: se repara HOY mismo',
            self::RentaExterna  => 'Dejar la agenda en pie: se rentará una máquina externa',
        };
    }

    /**
     * ¿La agenda futura NO se toca? (reparación el mismo día, o renta
     * externa que cubrirá los días comprometidos).
     */
    public function quedaEnPie(): bool
    {
        return $this === self::ReparacionHoy || $this === self::RentaExterna;
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

    /**
     * @return array<string, string>
     */
    public static function descripciones(): array
    {
        return [
            self::Cancelar->value      => 'Se reagenda cuando salga del taller.',
            self::Sustituta->value     => 'La sustituta toma la obra y hereda los días agendados.',
            self::ReparacionHoy->value => 'Solo si la reparación sale hoy: si mañana no llega, el encargado deja constancia de "no llegó".',
            self::RentaExterna->value  => 'Aviso a maquinaria y gerencia para gestionar el alquiler; cada día queda anotado "SE CUBRE CON RENTA EXTERNA".',
        ];
    }
}
