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
 *   deriva de la máquina: tarifa_hora × horas_dia_renta (ajustable
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
    case Semana = 'semana';
    case Mes = 'mes';
    case Viaje = 'viaje';
    case Kilometro = 'kilometro';

    /**
     * Días que incluye una semana de renta. Seis: en Honduras se trabaja de
     * lunes a sábado.
     */
    public const int DIAS_POR_SEMANA = 6;

    /** Días que incluye un mes de renta (4 semanas de 6 días). */
    public const int DIAS_POR_MES = 24;

    public function getLabel(): string
    {
        return match ($this) {
            self::Hora      => 'Horas',
            self::Dia       => 'Días',
            self::Semana    => 'Semanas',
            self::Mes       => 'Meses',
            self::Viaje     => 'Viajes',
            self::Kilometro => 'Kilómetros',
        };
    }

    /**
     * Precio sugerido de esta presentación, tomado del rate card de la
     * máquina.
     *
     * Cada presentación lleva SU precio: no se derivan una de otra. El día
     * no es 8 × la hora ni la semana 6 × el día — el mercado de renta cobra
     * con descuento por volumen (la hora ronda el 15% del día, el día el 25%
     * de la semana). Derivarlas sobrecotizaba ~20% y espantaba clientes.
     */
    public function tarifaSugerida(Maquina $maquina): string
    {
        return match ($this) {
            self::Hora      => (string) $maquina->tarifa_hora,
            self::Dia       => (string) ($maquina->tarifa_dia ?? '0'),
            self::Semana    => (string) ($maquina->tarifa_semana ?? '0'),
            self::Mes       => (string) ($maquina->tarifa_mes ?? '0'),
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
        $horasDia = (string) $maquina->horas_dia_renta;

        return match ($this) {
            self::Hora   => $cantidad,
            self::Dia    => bcmul($cantidad, $horasDia, 2),
            self::Semana => bcmul($cantidad, bcmul($horasDia, (string) self::DIAS_POR_SEMANA, 2), 2),
            self::Mes    => bcmul($cantidad, bcmul($horasDia, (string) self::DIAS_POR_MES, 2), 2),
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
            self::Hora, self::Dia, self::Semana, self::Mes => 'horas',
            self::Viaje                                    => 'viajes',
            self::Kilometro                                => 'km',
        };
    }

    /**
     * Sufijo del campo CANTIDAD en los formularios. ÚNICA fuente: lo
     * usan la tabla de líneas y el modal de extender renta, que antes
     * rotulaba "horas" aunque se cobrara por viajes (2026-08-16).
     */
    public function sufijoCantidad(): string
    {
        return match ($this) {
            self::Hora      => 'horas',
            self::Dia       => 'días',
            self::Semana    => 'semanas',
            self::Mes       => 'meses',
            self::Viaje     => 'viajes',
            self::Kilometro => 'km',
        };
    }

    /**
     * Sufijo del campo TARIFA en los formularios.
     */
    public function sufijoTarifa(): string
    {
        return match ($this) {
            self::Hora      => 'por hora',
            self::Dia       => 'por día',
            self::Semana    => 'por semana',
            self::Mes       => 'por mes',
            self::Viaje     => 'por viaje',
            self::Kilometro => 'por km',
        };
    }

    /**
     * ¿La cantidad se cuenta entera? Medio viaje no existe; media hora,
     * medio día, media semana y medio kilómetro sí.
     */
    public function esEntera(): bool
    {
        return $this === self::Viaje;
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
