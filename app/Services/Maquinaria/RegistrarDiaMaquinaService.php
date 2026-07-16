<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\EstadoAsignacion;
use App\Exceptions\Maquinaria\MaquinariaException;
use App\Models\AsignacionMaquina;
use App\Models\ConsumoCombustible;
use App\Models\ParteTrabajo;

/**
 * Captura del día — el evento real del negocio en UN solo guardado:
 * "hoy la máquina X trabajó N horas en la obra Y y quemó L litros a P".
 *
 * Orquesta las puertas únicas existentes (RegistrarParteService para horas
 * y RegistrarConsumoCombustibleService para combustible): NO duplica sus
 * reglas — horas extra, jornada, asignación activa y costos congelados
 * siguen viviendo ahí.
 *
 * Cada fila es independiente: la que falla se salta y se reporta; el
 * resto se registra igual (con 30 máquinas, abortar todo por una fila
 * sería contraproducente).
 */
final class RegistrarDiaMaquinaService
{
    public function __construct(
        private readonly RegistrarParteService $partes,
        private readonly RegistrarConsumoCombustibleService $combustible,
    ) {}

    /**
     * Registra el día completo. Filas sin horas NI litros se ignoran
     * (la máquina no trabajó — el hueco del calendario lo dice).
     *
     * @param list<array<string, mixed>> $filas Cada fila: asignacion_id,
     *                                          horas, motivo_extra, litros,
     *                                          precio_litro, operador.
     *
     * @return array{partes: int, consumos: int, saltados: list<string>}
     */
    public function capturar(string $fecha, array $filas, ?int $userId = null): array
    {
        $partes = 0;
        $consumos = 0;
        $saltados = [];

        foreach ($filas as $fila) {
            $asignacion = AsignacionMaquina::with('maquina:id,nombre')
                ->find((int) ($fila['asignacion_id'] ?? 0));

            if ($asignacion === null) {
                continue;
            }

            $etiqueta = $asignacion->maquina->nombre;
            $horas = self::numero($fila['horas'] ?? null);
            $litros = self::numero($fila['litros'] ?? null);
            $precioLitro = self::numero($fila['precio_litro'] ?? null);

            if ($horas !== null) {
                try {
                    $this->partes->registrarManual(
                        asignacion: $asignacion,
                        horas: $horas,
                        fecha: $fecha,
                        motivoHorasExtra: self::texto($fila['motivo_extra'] ?? null),
                        operador: self::texto($fila['operador'] ?? null),
                        userId: $userId,
                    );
                    $partes++;
                } catch (MaquinariaException $e) {
                    $saltados[] = "{$etiqueta} (horas): {$e->getMessage()}";
                }
            }

            if ($litros !== null) {
                if ($precioLitro === null) {
                    $saltados[] = "{$etiqueta} (combustible): falta el precio por litro.";

                    continue;
                }

                try {
                    $this->combustible->registrar(
                        asignacion: $asignacion,
                        litros: $litros,
                        precioLitro: $precioLitro,
                        fecha: $fecha,
                        operador: self::texto($fila['operador'] ?? null),
                        userId: $userId,
                    );
                    $consumos++;
                } catch (MaquinariaException $e) {
                    $saltados[] = "{$etiqueta} (combustible): {$e->getMessage()}";
                }
            }
        }

        return ['partes' => $partes, 'consumos' => $consumos, 'saltados' => $saltados];
    }

    /**
     * Filas prellenadas para la planilla del día: todas las asignaciones
     * ACTIVAS (máquina → obra con tarifa) con marcas de lo ya registrado.
     * Las horas van vacías: las REALES las escribe quien captura — la
     * agenda ya no estima horas (decisión Mauricio 2026-07-14).
     *
     * @return list<array<string, mixed>>
     */
    public function filasDelDia(string $fecha): array
    {
        $asignaciones = AsignacionMaquina::query()
            ->with(['maquina:id,nombre', 'proyecto:id,nombre'])
            ->where('estado', EstadoAsignacion::Activa->value)
            ->get()
            ->sortBy(fn (AsignacionMaquina $a): string => $a->maquina->nombre)
            ->values();

        if ($asignaciones->isEmpty()) {
            return [];
        }

        $ids = $asignaciones->pluck('id');

        // Lo ya capturado ese día (aviso anti doble captura).
        $conParte = ParteTrabajo::query()
            ->whereIn('asignacion_maquina_id', $ids)
            ->whereDate('fecha', $fecha)
            ->pluck('asignacion_maquina_id')
            ->flip();

        $conConsumo = ConsumoCombustible::query()
            ->whereIn('asignacion_maquina_id', $ids)
            ->whereDate('fecha', $fecha)
            ->pluck('asignacion_maquina_id')
            ->flip();

        // Referencia: el último precio de litro usado en el sistema.
        $ultimoPrecio = $this->ultimoPrecioLitro();

        return array_values($asignaciones->map(function (AsignacionMaquina $a) use ($conParte, $conConsumo, $ultimoPrecio): array {
            $marcas = array_filter([
                $conParte->has($a->id) ? '✓ horas' : null,
                $conConsumo->has($a->id) ? '✓ combustible' : null,
            ]);

            return [
                'asignacion_id' => $a->id,
                'etiqueta'      => "{$a->maquina->nombre} → {$a->proyecto->nombre}",
                'horas'         => null,
                'litros'        => null,
                'precio_litro'  => $ultimoPrecio,
                'motivo_extra'  => null,
                'operador'      => null,
                'ya_registrado' => $marcas === [] ? '' : implode(' · ', $marcas),
            ];
        })->all());
    }

    /**
     * Normaliza un input numérico de la planilla: vacío/0 → null (no se
     * registra), cualquier otro valor → string para bcmath.
     */
    /**
     * Último precio por litro usado en el sistema, recortado para la UI
     * ('39.75' — la DB lo guarda decimal(_,4)). Prellenado de formularios.
     */
    public function ultimoPrecioLitro(): ?string
    {
        $precio = ConsumoCombustible::query()
            ->latest('id')
            ->value('precio_litro');

        return $precio !== null
            ? rtrim(rtrim((string) $precio, '0'), '.')
            : null;
    }

    private static function texto(mixed $valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? $valor : null;
    }

    private static function numero(mixed $valor): ?string
    {
        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            return null;
        }

        return bccomp((string) $valor, '0', 2) === 1 ? (string) $valor : null;
    }
}
