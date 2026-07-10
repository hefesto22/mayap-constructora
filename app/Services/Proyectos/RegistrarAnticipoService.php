<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\EstadoProyecto;
use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Models\Proyecto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra el anticipo / depósito que el cliente entrega para la obra.
 *
 * En esta etapa el anticipo se guarda en el propio proyecto (monto,
 * fecha y marca de recibido). Más adelante puede engancharse al módulo
 * de Cuentas por Cobrar para generar el cobro formal — la firma de este
 * Service no cambia cuando eso ocurra.
 *
 * Solo se permite desde Aprobada o durante la ejecución (En ejecución /
 * Pausada): registrar un anticipo en borrador, enviada o un estado
 * terminal no tiene sentido de negocio.
 */
final class RegistrarAnticipoService
{
    private const array ESTADOS_PERMITIDOS = [
        EstadoProyecto::Aprobada,
        EstadoProyecto::EnEjecucion,
        EstadoProyecto::Pausada,
    ];

    public function ejecutar(Proyecto $proyecto, float|string $monto, ?Carbon $fecha = null): Proyecto
    {
        $montoStr = number_format((float) $monto, 2, '.', '');

        if (bccomp($montoStr, '0', 2) <= 0) {
            throw DatosEjecucionInvalidosException::anticipoInvalido($montoStr);
        }

        if (! in_array($proyecto->estado, self::ESTADOS_PERMITIDOS, strict: true)) {
            throw DatosEjecucionInvalidosException::estadoNoPermiteAnticipo($proyecto->estado);
        }

        $fechaAnticipo = ($fecha ?? Carbon::today())->copy()->startOfDay();

        return DB::transaction(function () use ($proyecto, $montoStr, $fechaAnticipo): Proyecto {
            $fresco = Proyecto::query()
                ->lockForUpdate()
                ->findOrFail($proyecto->id);

            if (! in_array($fresco->estado, self::ESTADOS_PERMITIDOS, strict: true)) {
                throw DatosEjecucionInvalidosException::estadoNoPermiteAnticipo($fresco->estado);
            }

            $fresco->forceFill([
                'anticipo_monto'    => $montoStr,
                'anticipo_fecha'    => $fechaAnticipo,
                'anticipo_recibido' => true,
            ])->save();

            return $fresco->refresh();
        });
    }
}
