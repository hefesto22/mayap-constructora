<?php

declare(strict_types=1);

namespace App\Services\Catalogos;

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\Zona;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\CauserResolver;
use Spatie\Activitylog\Models\Activity;

/**
 * Clona los items activos de una zona origen hacia una zona destino.
 *
 * Caso de uso: cuando el usuario crea una nueva zona (TGU, SPS), no
 * empieza desde cero — hereda el catálogo de items de una zona ya
 * trabajada (ej: SRC) como punto de partida y solo ajusta precios
 * locales. Los items son INDEPENDIENTES después de clonar: editar
 * el cemento en TGU NO afecta el cemento en SRC.
 *
 * Reglas de negocio:
 *  - Solo se clonan items con activo=true en la zona origen.
 *  - El código (zona+cat+correlativo) se REGENERA en la zona destino,
 *    no se copia (rompería el patrón de naming).
 *  - precio_actualizado_at se setea a now() vía ItemObserver.
 *  - Por default, se saltan items que ya existen en destino (mismo
 *    nombre uppercase + misma categoría). Configurable.
 *
 * Concurrencia: la operación va en transacción única. La generación
 * del código de cada item nuevo ya tiene su propio lockForUpdate
 * dentro del modelo (ver Item::generarCodigoSiguiente), pero todos
 * comparten el mismo commit final.
 *
 * Performance: síncrono para hasta ~500 items (probablemente <2s).
 * Si el catálogo crece a 2000+ items por zona, mover a Job.
 */
final class ClonarItemsEntreZonas
{
    /**
     * @return array{clonados: int, omitidos: int}
     *
     * @throws DomainException Si origen y destino son la misma zona.
     */
    public function ejecutar(Zona $origen, Zona $destino, bool $saltarDuplicados = true): array
    {
        if ($origen->id === $destino->id) {
            throw new DomainException(
                'No se puede clonar items de una zona hacia sí misma.'
            );
        }

        return DB::transaction(function () use ($origen, $destino, $saltarDuplicados): array {
            $clonados = 0;
            $omitidos = 0;
            $idsClonados = [];

            $itemsOrigen = Item::query()
                ->where('zona_id', $origen->id)
                ->where('activo', true)
                ->get();

            // Pre-cargar firmas de duplicados existentes en destino para
            // chequeo O(1) por item, en vez de query por cada uno.
            $firmasExistentes = $saltarDuplicados
                ? Item::query()
                    ->where('zona_id', $destino->id)
                    ->get(['nombre', 'categoria'])
                    ->mapWithKeys(fn (Item $i): array => [
                        $this->firmaItem($i->nombre, $i->categoria) => true,
                    ])
                : collect();

            foreach ($itemsOrigen as $item) {
                $firma = $this->firmaItem($item->nombre, $item->categoria);

                if ($saltarDuplicados && $firmasExistentes->has($firma)) {
                    $omitidos++;

                    continue;
                }

                $nuevo = Item::create([
                    'zona_id'              => $destino->id,
                    'unidad_medida_id'     => $item->unidad_medida_id,
                    'categoria'            => $item->categoria,
                    'nombre'               => $item->nombre,
                    'descripcion'          => $item->descripcion,
                    'precio_unitario'      => $item->precio_unitario,
                    'observaciones_precio' => $item->observaciones_precio,
                    'activo'               => true,
                    // codigo: NO se incluye → el creating event del modelo
                    // lo genera con el patrón {ZONA_DESTINO}-{CAT}-#####.
                ]);

                // Marcar la firma para que un mismo item dentro del lote no
                // se duplique (defensa extra ante datos inconsistentes).
                $firmasExistentes->put($firma, true);

                $idsClonados[] = $nuevo->id;
                $clonados++;
            }

            $this->registrarAuditoriaClonado(
                origen: $origen,
                destino: $destino,
                clonados: $clonados,
                omitidos: $omitidos,
                saltoDuplicados: $saltarDuplicados,
                idsClonados: $idsClonados,
            );

            return [
                'clonados' => $clonados,
                'omitidos' => $omitidos,
            ];
        });
    }

    /**
     * Registra una entrada semántica en activitylog para auditoría:
     * "Mauricio clonó 21 items de SRC → TGU el 27/04 14:30".
     *
     * Esto complementa los 21 logs individuales de "Item created" que
     * el trait LogsActivity ya genera por cada item — el log de aquí
     * agrupa la operación entera en un solo evento legible para humanos.
     *
     * Solo se registra si hubo movimiento real (clonados > 0) para no
     * ensuciar el log con clonados vacíos.
     *
     * @param array<int, int> $idsClonados
     */
    private function registrarAuditoriaClonado(
        Zona $origen,
        Zona $destino,
        int $clonados,
        int $omitidos,
        bool $saltoDuplicados,
        array $idsClonados,
    ): void {
        if ($clonados === 0) {
            return;
        }

        activity('clonado_items')
            ->causedBy(Auth::user() ?? CauserResolver::resolve())
            ->withProperties([
                'origen_id'        => $origen->id,
                'origen_codigo'    => $origen->codigo,
                'origen_nombre'    => $origen->nombre,
                'destino_id'       => $destino->id,
                'destino_codigo'   => $destino->codigo,
                'destino_nombre'   => $destino->nombre,
                'clonados'         => $clonados,
                'omitidos'         => $omitidos,
                'salto_duplicados' => $saltoDuplicados,
                'ids_clonados'     => $idsClonados,
            ])
            ->log("Clonado de items: {$origen->nombre} → {$destino->nombre} ({$clonados} clonados, {$omitidos} omitidos)");
    }

    /**
     * Cuenta cuántas veces se clonó hacia una zona específica. Útil para
     * el feature futuro "deshacer último clonado" o para auditoría.
     */
    public static function totalClonadosHaciaZona(int $zonaId): int
    {
        return Activity::query()
            ->where('log_name', 'clonado_items')
            ->whereJsonContains('properties->destino_id', $zonaId)
            ->count();
    }

    /**
     * Firma única de un item para detectar duplicados.
     *
     * Combina nombre uppercase (ya viene así por el mutator del modelo,
     * pero defensivo) + valor del enum categoría. Dos items con mismo
     * nombre pero distinta categoría son items DISTINTOS — caso real:
     * "FLETE" puede aparecer como Indirecto y como Mano de obra (no
     * frecuente, pero válido).
     */
    private function firmaItem(string $nombre, CategoriaItem $categoria): string
    {
        return mb_strtoupper(trim($nombre), 'UTF-8').'|'.$categoria->value;
    }
}
