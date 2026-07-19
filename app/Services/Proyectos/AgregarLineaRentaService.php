<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\UnidadRenta;
use App\Exceptions\Proyectos\RentaInvalidaException;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use Illuminate\Support\Facades\DB;

/**
 * Única puerta para agregar líneas a un proyecto de renta de maquinaria.
 *
 * Reglas:
 *  - El proyecto debe ser tipo renta_maquinaria.
 *  - Líneas normales solo en Borrador (igual que los renglones APU).
 *    Las EXTENSIONES post-aprobación entran por ExtenderRentaService,
 *    que llama aquí con $esExtension = true.
 *  - La tarifa, si no se indica, se sugiere del catálogo de la máquina
 *    según la unidad (hora → tarifa_hora; día → tarifa_hora × jornada).
 *    Queda como SNAPSHOT en la línea.
 *  - Recalcula los totales del proyecto al terminar (misma calculadora
 *    que los presupuestados — CalcularPrecioProyectoService ya sabe
 *    sumar líneas de renta cuando el tipo es renta).
 */
final class AgregarLineaRentaService
{
    public function __construct(
        private readonly CalcularPrecioProyectoService $calculadora,
    ) {}

    public function agregar(
        Proyecto $proyecto,
        int $maquinaId,
        UnidadRenta $unidad,
        string $cantidad,
        string $fechaLlegada,
        ?string $horaLlegada = null,
        ?string $tarifa = null,
        ?string $notas = null,
        bool $esExtension = false,
    ): ProyectoLineaRenta {
        if (! $proyecto->esRenta()) {
            throw RentaInvalidaException::noEsRenta($proyecto->codigo);
        }

        if (! $esExtension && ! $proyecto->estado->permiteEditar()) {
            throw RentaInvalidaException::estadoNoPermiteAgregarLineas($proyecto->estado);
        }

        $maquina = Maquina::findOrFail($maquinaId);

        return DB::transaction(function () use ($proyecto, $maquina, $unidad, $cantidad, $fechaLlegada, $horaLlegada, $tarifa, $notas, $esExtension): ProyectoLineaRenta {
            $ordenSiguiente = (int) $proyecto->lineasRenta()->max('orden') + 1;

            $linea = ProyectoLineaRenta::create([
                'proyecto_id'     => $proyecto->id,
                'maquina_id'      => $maquina->id,
                'orden'           => $ordenSiguiente,
                'unidad'          => $unidad->value,
                'cantidad'        => $cantidad,
                'tarifa_snapshot' => $tarifa ?? $unidad->tarifaSugerida($maquina),
                'fecha_llegada'   => $fechaLlegada,
                'hora_llegada'    => $horaLlegada,
                'es_extension'    => $esExtension,
                'notas'           => $notas,
            ]);

            $this->calculadora->recalcular($proyecto);

            return $linea;
        });
    }
}
