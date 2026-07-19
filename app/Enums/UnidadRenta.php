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
 *
 * La conversión a HORAS EQUIVALENTES (para comparar contra las horas
 * reales del parte al finalizar) usa la jornada de la máquina:
 * 2 días × jornada 8h = 16 horas pactadas.
 *
 * El CHECK constraint de `proyecto_lineas_renta` valida el conjunto.
 */
enum UnidadRenta: string implements HasLabel
{
    case Hora = 'hora';
    case Dia = 'dia';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hora => 'Horas',
            self::Dia  => 'Días',
        };
    }

    /**
     * Tarifa sugerida para esta unidad según el catálogo de la máquina.
     * Hora → tarifa_hora; Día → tarifa_hora × jornada_horas.
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
        };
    }

    /**
     * Horas equivalentes de una cantidad en esta unidad, según la
     * jornada de la máquina. Se usa para comparar pactado vs real.
     */
    public function horasEquivalentes(string $cantidad, Maquina $maquina): string
    {
        return match ($this) {
            self::Hora => $cantidad,
            self::Dia  => bcmul($cantidad, (string) $maquina->jornada_horas, 2),
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
