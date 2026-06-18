<?php

declare(strict_types=1);

namespace App\Services\Fichas;

use App\Enums\TipoLineaFicha;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\Zona;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\CauserResolver;
use Spatie\Activitylog\Models\Activity;

/**
 * Duplica una ficha APU completa a otra zona.
 *
 * Estrategia:
 *  - La ficha destino es independiente: cambios en una NO afectan la otra.
 *  - El nombre puede repetirse entre zonas (es único por zona, no global).
 *  - Para cada línea tipo='item' en la ficha origen:
 *     1. Busca el item equivalente en zona destino por (nombre, categoria).
 *     2. Si existe → reutiliza ese item.
 *     3. Si NO existe → crea uno nuevo en zona destino con precio 0 y
 *        observación "Creado por duplicación de ficha — revisar precio".
 *  - Las líneas tipo='porcentaje' se copian sin transformación (no
 *    dependen de items).
 *  - El código auto-generado de la ficha destino sigue el patrón de
 *    su zona ({ZONA_DESTINO}-APU-#####).
 *
 * Reporta cuántos items fueron reutilizados vs creados con precio 0,
 * para que el usuario sepa qué precios completar manualmente después.
 *
 * Auditoría: una entrada en activitylog (log_name=duplicado_ficha) con
 * properties que detallan origen, destino, conteos, e IDs creados.
 *
 * @phpstan-type ResultadoDuplicacion array{
 *     ficha_destino: Ficha,
 *     items_reutilizados: int,
 *     items_creados: int,
 *     ids_items_creados: list<int>,
 * }
 */
final class DuplicarFichaAOtraZona
{
    /**
     * Ejecuta la duplicación.
     *
     * @return array{
     *     ficha_destino: Ficha,
     *     items_reutilizados: int,
     *     items_creados: int,
     *     ids_items_creados: list<int>,
     * }
     */
    public function ejecutar(Ficha $fichaOrigen, Zona $zonaDestino): array
    {
        return DB::transaction(function () use ($fichaOrigen, $zonaDestino): array {
            $fichaOrigen->loadMissing(['lineas.item.unidadMedida']);

            // 1) Crear la ficha destino con misma metadata.
            $fichaDestino = Ficha::create([
                'zona_id'             => $zonaDestino->id,
                'unidad_medida_id'    => $fichaOrigen->unidad_medida_id,
                'nombre'              => $fichaOrigen->nombre,
                'descripcion'         => $fichaOrigen->descripcion,
                'parametros_tecnicos' => $fichaOrigen->parametros_tecnicos,
                'utilidad_porcentaje' => $fichaOrigen->utilidad_porcentaje,
                'activa'              => true,
            ]);

            $itemsReutilizados = 0;
            $itemsCreados = 0;
            $idsCreados = [];

            // 2) Replicar las líneas.
            foreach ($fichaOrigen->lineas as $lineaOrigen) {
                if ($lineaOrigen->esPorcentaje()) {
                    // Línea derivada — se copia sin más, no depende de items.
                    FichaLinea::create([
                        'ficha_id'          => $fichaDestino->id,
                        'tipo'              => TipoLineaFicha::Porcentaje,
                        'orden'             => $lineaOrigen->orden,
                        'descripcion'       => $lineaOrigen->descripcion,
                        'porcentaje'        => $lineaOrigen->porcentaje,
                        'categoria_base'    => $lineaOrigen->categoria_base,
                        'categoria_destino' => $lineaOrigen->categoria_destino,
                        'notas'             => $lineaOrigen->notas,
                    ]);

                    continue;
                }

                // Línea tipo item — buscar equivalente en zona destino.
                $itemOrigen = $lineaOrigen->item;

                if ($itemOrigen === null) {
                    continue;
                }

                $itemDestino = $this->encontrarOCrearItemEnZona(
                    itemOrigen: $itemOrigen,
                    zonaDestino: $zonaDestino,
                );

                if ($itemDestino->wasRecentlyCreated) {
                    $itemsCreados++;
                    $idsCreados[] = $itemDestino->id;
                } else {
                    $itemsReutilizados++;
                }

                FichaLinea::create([
                    'ficha_id'               => $fichaDestino->id,
                    'tipo'                   => TipoLineaFicha::Item,
                    'orden'                  => $lineaOrigen->orden,
                    'item_id'                => $itemDestino->id,
                    'rendimiento'            => $lineaOrigen->rendimiento,
                    'desperdicio_porcentaje' => $lineaOrigen->desperdicio_porcentaje,
                    'notas'                  => $lineaOrigen->notas,
                ]);
            }

            // 3) Recalcular cache del precio en la ficha destino.
            (new CalcularPrecioFichaService)->recalcularYPersistir($fichaDestino);

            // 4) Auditoría semántica en activitylog.
            $properties = [
                'origen_ficha_id'    => $fichaOrigen->id,
                'origen_codigo'      => $fichaOrigen->codigo,
                'origen_zona'        => $fichaOrigen->zona->codigo ?? null,
                'destino_ficha_id'   => $fichaDestino->id,
                'destino_codigo'     => $fichaDestino->fresh()->codigo,
                'destino_zona'       => $zonaDestino->codigo,
                'items_reutilizados' => $itemsReutilizados,
                'items_creados'      => $itemsCreados,
                'ids_items_creados'  => $idsCreados,
            ];

            activity('duplicado_ficha')
                ->causedBy(CauserResolver::resolve())
                ->performedOn($fichaDestino)
                ->withProperties($properties)
                ->event('duplicada')
                ->log("Ficha {$fichaOrigen->codigo} duplicada a zona {$zonaDestino->codigo}");

            return [
                'ficha_destino'      => $fichaDestino->fresh(),
                'items_reutilizados' => $itemsReutilizados,
                'items_creados'      => $itemsCreados,
                'ids_items_creados'  => $idsCreados,
            ];
        });
    }

    /**
     * Busca un item en la zona destino por (nombre, categoria).
     * Si no existe, lo crea con precio 0 y observación de revisión.
     *
     * Usa el mutator uppercase del modelo Item para que la comparación
     * sea consistente (los nombres se persisten en mayúsculas).
     */
    private function encontrarOCrearItemEnZona(Item $itemOrigen, Zona $zonaDestino): Item
    {
        $existente = Item::query()
            ->where('zona_id', $zonaDestino->id)
            ->where('categoria', $itemOrigen->categoria->value)
            ->whereRaw('UPPER(nombre) = ?', [mb_strtoupper((string) $itemOrigen->nombre, 'UTF-8')])
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        return Item::create([
            'zona_id'              => $zonaDestino->id,
            'unidad_medida_id'     => $itemOrigen->unidad_medida_id,
            'categoria'            => $itemOrigen->categoria,
            'nombre'               => $itemOrigen->nombre,
            'descripcion'          => $itemOrigen->descripcion,
            'precio_unitario'      => 0,
            'observaciones_precio' => 'CREADO POR DUPLICACIÓN DE FICHA — REVISAR PRECIO',
            'activo'               => true,
        ]);
    }

    /**
     * Recupera la última operación de duplicación a partir del activitylog.
     * Útil para mostrar el resultado de la duplicación al usuario.
     */
    public static function ultimaActividadDeFicha(Ficha $ficha): ?Activity
    {
        return Activity::query()
            ->where('log_name', 'duplicado_ficha')
            ->where('subject_type', Ficha::class)
            ->where('subject_id', $ficha->id)
            ->latest()
            ->first();
    }
}
