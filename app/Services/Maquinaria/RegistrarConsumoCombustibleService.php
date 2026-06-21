<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Exceptions\Maquinaria\CombustibleInvalidoException;
use App\Models\AsignacionMaquina;
use App\Models\ConsumoCombustible;
use Illuminate\Support\Facades\DB;

/**
 * Registra consumos de combustible de una máquina asignada a una obra. El
 * costo (litros × precio) se carga a la obra, igual que las horas de trabajo.
 *
 * Es la única puerta para crear consumos: valida que la asignación esté
 * activa y congela el precio y el costo al momento de la carga.
 */
final class RegistrarConsumoCombustibleService
{
    private const int SCALE_INTERNO = 12;

    private const int SCALE_LITROS = 2;

    private const int SCALE_MONTO = 2;

    public function registrar(
        AsignacionMaquina $asignacion,
        string $litros,
        string $precioLitro,
        ?string $fecha = null,
        ?string $operador = null,
        ?int $userId = null,
        ?string $notas = null,
    ): ConsumoCombustible {
        if (bccomp($litros, '0', self::SCALE_LITROS) <= 0) {
            throw CombustibleInvalidoException::cantidadInvalida($litros);
        }

        return DB::transaction(function () use ($asignacion, $litros, $precioLitro, $fecha, $operador, $userId, $notas): ConsumoCombustible {
            $asignacionBloqueada = AsignacionMaquina::query()
                ->whereKey($asignacion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $asignacionBloqueada->estado->esActiva()) {
                throw CombustibleInvalidoException::asignacionNoActiva($asignacionBloqueada->codigo);
            }

            $costo = $this->bcround(bcmul($litros, $precioLitro, self::SCALE_INTERNO), self::SCALE_MONTO);

            return ConsumoCombustible::create([
                'asignacion_maquina_id' => $asignacionBloqueada->id,
                'fecha'                 => $fecha ?? now()->toDateString(),
                'cantidad_litros'       => $litros,
                'precio_litro'          => $precioLitro,
                'costo_cache'           => $costo,
                'operador'              => $operador,
                'notas'                 => $notas,
                'user_id'               => $userId,
            ]);
        });
    }

    private function bcround(string $value, int $scale): string
    {
        $factor = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', self::SCALE_INTERNO) >= 0) {
            return bcadd($value, $factor, $scale);
        }

        return bcsub($value, $factor, $scale);
    }
}
