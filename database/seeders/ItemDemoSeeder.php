<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Catalogos\VincularMaterialAItemService;
use Illuminate\Database\Seeder;

/**
 * Seeder de items demo con precios realistas del mercado hondureño
 * (ejercicios 2025-2026).
 *
 * Propósito:
 *  - Acelerar el setup local: tras `migrate:fresh` no hay que cargar
 *    items a mano para probar el sistema.
 *  - Servir de base para Sprint 2 (Fichas APU) — las fichas necesitan
 *    items existentes para componerse.
 *
 * NO se incluye en CatalogosSeeder ni DatabaseSeeder por defecto: los
 * datos demo NO van a producción ni staging. Para cargarlo en local:
 *
 *   php artisan db:seed --class=Database\\Seeders\\ItemDemoSeeder
 *
 * Idempotente vía la combinación zona+categoría+nombre (no se duplican
 * si se corre múltiples veces).
 *
 * BASE DE PRECIOS ↔ MATERIAL FÍSICO (ADR-0003): para las categorías
 * inventariables (materiales, herramienta y equipo) este seeder además
 * busca-o-crea el `Material` físico canónico (global) y enlaza el item de
 * precio a él vía `material_id`. Así el inventario referencia el material
 * único, mientras el item conserva el precio de venta de su zona.
 */
class ItemDemoSeeder extends Seeder
{
    public function run(): void
    {
        $zona = Zona::where('codigo', 'SRC')->first();

        if ($zona === null) {
            $this->command->warn('Zona SRC no existe. Ejecuta primero ZonaSeeder.');

            return;
        }

        // Asegura las unidades que usa la ficha real del cliente (crea las
        // que falten). Devuelve mapa codigo => id.
        $unidades = [
            'BOLSA' => $this->unidad('BOLSA', 'Bolsa'),
            'M3'    => $this->unidad('M3', 'Metro cúbico', 'm³'),
            'PT'    => $this->unidad('PT', 'Pie tablar', 'pt'),
            'LANCE' => $this->unidad('LANCE', 'Lance'),
            'LIBRA' => $this->unidad('LIBRA', 'Libra', 'lb'),
            'LB'    => $this->unidad('LB', 'Libra', 'lb'),
            'JDR'   => $this->unidad('JDR', 'Jornada'),
            'DIA'   => $this->unidad('DIA', 'Día'),
        ];

        // Precios y desperdicios EXACTOS de la ficha real del cliente (Excel
        // "LOSA DE CONCRETO ALIGERADA"). Armar esa ficha con estos items
        // reproduce el total L2,604.37.
        // Formato: [nombre, unidad, categoría, precio (PU), desperdicio %, observaciones]
        $items = [
            // ─── Materiales ──────────────────────────────────────────
            ['Cemento',           'BOLSA', CategoriaItem::Materiales, 220.00,  5, 'Saco 42.5kg'],
            ['Arena',             'M3',    CategoriaItem::Materiales, 600.00, 10, null],
            ['Grava trit 3/4',    'M3',    CategoriaItem::Materiales, 750.00, 10, null],
            ['Agua',              'M3',    CategoriaItem::Materiales, 100.00, 25, null],
            ['Lámina de aluzinc', 'PT',    CategoriaItem::Materiales,  52.00,  5, null],
            ['Canaleta 2x4',      'LANCE', CategoriaItem::Materiales, 450.00,  5, null],
            ['Var#4',             'LANCE', CategoriaItem::Materiales, 270.00,  5, null],
            ['Alambre de amarre', 'LIBRA', CategoriaItem::Materiales,  20.00,  5, null],
            ['Tornillos',         'LB',    CategoriaItem::Materiales,   2.50, 10, null],
            ['Clavos',            'LIBRA', CategoriaItem::Materiales,  25.00,  5, null],

            // ─── Mano de obra ────────────────────────────────────────
            ['Albañil',           'JDR',   CategoriaItem::ManoObra,   750.00,  0, null],
            ['Soldador',          'JDR',   CategoriaItem::ManoObra,   750.00,  0, null],
            ['Ayudante',          'JDR',   CategoriaItem::ManoObra,   450.00,  0, null],

            // ─── Herramienta y equipo ────────────────────────────────
            ['Concretera',        'DIA',   CategoriaItem::HerramientaEquipo, 1000.00, 0, null],
            ['Vibrador',          'DIA',   CategoriaItem::HerramientaEquipo,  700.00, 0, null],
            ['Soldadora',         'DIA',   CategoriaItem::HerramientaEquipo,  400.00, 0, null],
        ];

        $creados = 0;
        $existentes = 0;

        foreach ($items as [$nombre, $unidadCodigo, $categoria, $precio, $desperdicio, $observaciones]) {
            $existe = Item::where('zona_id', $zona->id)
                ->where('categoria', $categoria->value)
                ->whereRaw('UPPER(nombre) = ?', [mb_strtoupper($nombre, 'UTF-8')])
                ->exists();

            if ($existe) {
                $existentes++;

                continue;
            }

            // Material físico canónico para categorías inventariables; las
            // no inventariables (mano de obra, indirectos) quedan sin material.
            $materialId = $this->materialParaItem($categoria, $nombre, $unidades[$unidadCodigo]);

            // Código se autogenera vía el creating event del modelo
            Item::create([
                'material_id'            => $materialId,
                'zona_id'                => $zona->id,
                'unidad_medida_id'       => $unidades[$unidadCodigo],
                'categoria'              => $categoria,
                'nombre'                 => $nombre,
                'precio_unitario'        => $precio,
                'desperdicio_porcentaje' => $desperdicio,
                'observaciones_precio'   => $observaciones,
                'activo'                 => true,
            ]);

            $creados++;
        }

        $this->command->info("✓ ItemDemoSeeder: {$creados} items creados, {$existentes} ya existían.");
    }

    /**
     * Material físico canónico del item. La regla vive en
     * VincularMaterialAItemService (única fuente — ver §8 de instrucciones).
     */
    private function materialParaItem(CategoriaItem $categoria, string $nombre, int $unidadId): ?int
    {
        return app(VincularMaterialAItemService::class)
            ->materialCanonicoPara($categoria, $nombre, $unidadId);
    }

    /**
     * Busca o crea una unidad de medida por código. Devuelve su id.
     */
    private function unidad(string $codigo, string $nombre, ?string $simbolo = null): int
    {
        return UnidadMedida::firstOrCreate(
            ['codigo' => $codigo],
            ['nombre' => $nombre, 'simbolo' => $simbolo, 'activo' => true],
        )->id;
    }
}
