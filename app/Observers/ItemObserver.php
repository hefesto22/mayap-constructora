<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Item;

/**
 * Mantiene `precio_actualizado_at` sincronizado con cambios reales del
 * precio.
 *
 * Diseño deliberado: NO usar `updated_at` para "última actualización
 * de precio" porque `updated_at` cambia con cualquier edit (corregir
 * un typo en descripción, marcar inactivo, etc). El Observer detecta
 * exactamente el delta de `precio_unitario` y nada más.
 *
 * - Al crear: si viene precio > 0, se setea precio_actualizado_at = now().
 * - Al actualizar: si `precio_unitario` está dirty respecto al original,
 *   se setea precio_actualizado_at = now().
 *
 * Se usa el hook `saving` (antes de persistir) para que la columna
 * quede dentro del mismo INSERT/UPDATE, sin un segundo query.
 */
class ItemObserver
{
    public function saving(Item $item): void
    {
        // Caso CREATE: si trae precio definido, marcar la fecha.
        if (! $item->exists) {
            if ((float) $item->precio_unitario > 0 && $item->precio_actualizado_at === null) {
                $item->precio_actualizado_at = now();
            }

            return;
        }

        // Caso UPDATE: solo si precio_unitario cambió.
        if ($item->isDirty('precio_unitario')) {
            $item->precio_actualizado_at = now();
        }
    }
}
