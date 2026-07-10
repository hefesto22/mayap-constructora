<?php

declare(strict_types=1);

namespace App\Services\Catalogos;

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\Material;

/**
 * Vincula items del catálogo de precios con su Material físico canónico.
 *
 * REGLA DE DOMINIO (única fuente — no duplicar en seeders):
 *  - Solo las categorías inventariables (Materiales, Herramienta y Equipo)
 *    tienen material físico. Mano de obra e indirectos → null.
 *  - El material es GLOBAL por (categoría, nombre): items de distintas
 *    zonas con el mismo nombre comparten el MISMO material físico
 *    (ADR-0003: Material = inventario global, Item = precio por zona).
 *
 * Sin este vínculo, el item no participa en inventario, compras,
 * requisiciones ni en el control presupuestario de materiales por obra.
 */
final class VincularMaterialAItemService
{
    /**
     * Id del Material canónico para un item, creándolo si no existe.
     * Null para categorías no inventariables.
     */
    public function materialCanonicoPara(CategoriaItem $categoria, string $nombre, int $unidadId): ?int
    {
        if (! self::esInventariable($categoria)) {
            return null;
        }

        $material = Material::query()
            ->where('categoria', $categoria->value)
            ->whereRaw('UPPER(nombre) = ?', [mb_strtoupper($nombre, 'UTF-8')])
            ->first()
            ?? Material::create([
                'unidad_medida_id' => $unidadId,
                'categoria'        => $categoria,
                'nombre'           => $nombre,
                'activo'           => true,
            ]);

        return $material->id;
    }

    /**
     * Backfill idempotente: vincula todos los items inventariables que
     * quedaron sin material_id (creados por seeders o imports antiguos).
     * Devuelve la cantidad de items vinculados.
     */
    public function vincularFaltantes(): int
    {
        $vinculados = 0;

        Item::query()
            ->whereNull('material_id')
            ->whereIn('categoria', [
                CategoriaItem::Materiales->value,
                CategoriaItem::HerramientaEquipo->value,
            ])
            ->orderBy('id')
            ->lazyById(200)
            ->each(function (Item $item) use (&$vinculados): void {
                $materialId = $this->materialCanonicoPara(
                    $item->categoria,
                    $item->nombre,
                    $item->unidad_medida_id,
                );

                if ($materialId !== null) {
                    $item->update(['material_id' => $materialId]);
                    $vinculados++;
                }
            });

        return $vinculados;
    }

    public static function esInventariable(CategoriaItem $categoria): bool
    {
        return in_array(
            $categoria,
            [CategoriaItem::Materiales, CategoriaItem::HerramientaEquipo],
            strict: true,
        );
    }
}
