<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Proyecto;
use App\Services\Reportes\CostoProyecto;
use App\Services\Reportes\CostoProyectoService;
use WeakMap;

/**
 * Resolvedor memoizado del costo de una obra para la capa Filament.
 *
 * El `CostoProyectoService` agrega varias queries; en una tabla o un infolist
 * se consulta el mismo proyecto desde varias columnas/entradas. Este helper
 * cachea el resultado por INSTANCIA (WeakMap) durante el render para no
 * recalcular N veces. Al ser por instancia, no hay fugas entre requests ni
 * entre tests, y se libera solo cuando el modelo deja de usarse.
 */
final class CostoObra
{
    /** @var WeakMap<Proyecto, CostoProyecto>|null */
    private static ?WeakMap $cache = null;

    public static function para(Proyecto $proyecto): CostoProyecto
    {
        self::$cache ??= new WeakMap;

        return self::$cache[$proyecto] ??= app(CostoProyectoService::class)->calcular($proyecto);
    }
}
