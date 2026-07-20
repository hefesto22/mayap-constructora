<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Maquina;
use Filament\Support\Contracts\HasLabel;

/**
 * Unidad de cobro de una línea de renta de maquinaria.
 *
 * - Hora: cantidad × tarifa por hora.
 * - Dia: cantidad × tarifa por día. La tarifa diaria sugerida se
 *   deriva de la máquina: tarifa_hora × jornada_horas (ajustable
 *   al cotizar — la línea guarda SU tarifa como snapshot).
 * - Viaje: volquetas por viajes (origen → destino) — tarifa_viaje
 *   del catálogo de la máquina (decisión Mauricio 2026-07-20).
 * - Kilometro: pick-ups por km — tarifa_km del catálogo.
 *
 * La conversión a HORAS EQUIVALENTES aplica solo a hora/día (para
 * comparar contra las horas reales del parte al finalizar). Viajes y
 * km se comparan contra SUS datos de los partes (viajes reales, km
 * reales) — ver dimension().
 *
 * El CHECK constraint de `proyecto_lineas_renta` valida el conjunto.
 */
enum UnidadRenta: string implements HasLabel
{
    case Hora = 'hora';
    case Dia = 'dia';
    case Viaje = 'viaje';
    case Kilometro = 'kilometro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hora      => 'Horas',
            self::Dia       => 'Días',
            self::Viaje     => 'Viajes',
            self::Kilometro => 'Kilómetros',
        };
    }

    /**
     * Tarifa sugerida para esta unidad según el catálogo de la máquina.
     * Hora → tarifa_hora; Día → tarifa_hora × jornada_horas;
     * Viaje → tarifa_viaje; Km → tarifa_km (0 si no está en el catálogo).
     */
    public function tarifaSugerida(Maquina $maquina): string
    {
        return match ($this) {
            self::Hora => (string) $maquina->tarifa_hora,
            self::Dia  => bcmul(
                (string) $maquina->tarifa_hora,
                (string) $maquina->jornada_horas,
                2,
            ),
            self::Viaje     => (string) ($maquina->tarifa_viaje ?? '0'),
            self::Kilometro => (string) ($maquina->tarifa_km ?? '0'),
        };
    }

    /**
     * Horas equivalentes de una cantidad en esta unidad, según la
     * jornada de la máquina. Solo aplica a hora/día — viajes y km se
     * comparan en su propia dimensión (quien llame esto para viaje/km
     * recibe cero: no hay equivalencia horaria).
     */
    public function horasEquivalentes(string $cantidad, Maquina $maquina): string
    {
        return match ($this) {
            self::Hora => $cantidad,
            self::Dia  => bcmul($cantidad, (string) $maquina->jornada_horas, 2),
            self::Viaje,
            self::Kilometro => '0.00',
        };
    }

    /**
     * Dimensión en la que se compara pactado vs real al finalizar:
     * hora/día → horas de los partes; viaje → viajes de los partes;
     * kilómetro → km de los partes.
     */
    public function dimension(): string
    {
        return match ($this) {
            self::Hora, self::Dia => 'horas',
            self::Viaje           => 'viajes',
            self::Kilometro       => 'km',
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
