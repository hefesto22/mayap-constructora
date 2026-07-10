<?php

declare(strict_types=1);

namespace App\Services\Fichas;

use App\Models\Ficha;
use App\Models\Zona;

/**
 * Recalcula el cache de precio de TODAS las fichas activas de una zona,
 * en un solo paso. Resuelve el caso "actualicé varios precios de items y
 * quiero propagar a todas las fichas de la zona sin recalcular una por una".
 *
 * Responsabilidad única: orquestar el recálculo masivo por zona. La
 * matemática vive en CalcularPrecioFichaService; este service solo itera.
 *
 * Volumen y performance: para decenas de fichas por zona el recálculo
 * síncrono es instantáneo. Si una zona crece a cientos de fichas y el
 * recálculo supera el presupuesto de request (>500ms), el siguiente paso
 * natural es envolver esta misma llamada en un Job (la interfaz no cambia).
 * Se procesa con cursor() para mantener memoria O(1) sin importar el conteo.
 */
final readonly class RecalcularFichasDeZona
{
    public function __construct(
        private CalcularPrecioFichaService $calculadora,
    ) {}

    /**
     * Recalcula y persiste el cache de todas las fichas activas de la zona.
     *
     * @return int Cantidad de fichas recalculadas.
     */
    public function ejecutar(Zona $zona): int
    {
        $recalculadas = 0;

        Ficha::query()
            ->where('zona_id', $zona->id)
            ->where('activa', true)
            ->with(['lineas.item.unidadMedida'])
            ->cursor()
            ->each(function (Ficha $ficha) use (&$recalculadas): void {
                $this->calculadora->recalcularYPersistir($ficha);
                $recalculadas++;
            });

        return $recalculadas;
    }
}
