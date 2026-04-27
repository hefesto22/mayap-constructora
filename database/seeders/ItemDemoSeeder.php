<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
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

        // Map de unidades por código para resolver IDs sin queries N+1
        $unidades = UnidadMedida::pluck('id', 'codigo')->all();

        $items = [
            // ─── Materiales ──────────────────────────────────────────
            ['Cemento gris saco 50kg Argos',     'BOLSA', CategoriaItem::Materiales,  280.00, 'Marca más usada en la zona'],
            ['Cemento gris saco 50kg Holcim',    'BOLSA', CategoriaItem::Materiales,  295.00, null],
            ['Varilla #3 corrugada 6m grado 40', 'VAR',   CategoriaItem::Materiales,  165.00, 'Para estructuras menores'],
            ['Varilla #4 corrugada 6m grado 40', 'VAR',   CategoriaItem::Materiales,  290.00, null],
            ['Varilla #5 corrugada 6m grado 60', 'VAR',   CategoriaItem::Materiales,  445.00, null],
            ['Bloque de concreto 6"',            'UND',   CategoriaItem::Materiales,   18.50, null],
            ['Bloque de concreto 4"',            'UND',   CategoriaItem::Materiales,   13.00, null],
            ['Arena de río',                     'M3',    CategoriaItem::Materiales,  650.00, 'Precio en obra, incluye flete <10km'],
            ['Grava 3/4"',                       'M3',    CategoriaItem::Materiales,  720.00, null],
            ['Alambre de amarre #18',            'KG',    CategoriaItem::Materiales,   45.00, null],

            // ─── Mano de obra ────────────────────────────────────────
            ['Jornada albañil',                  'JDR',   CategoriaItem::ManoObra,    550.00, 'Sin alimentación'],
            ['Jornada ayudante',                 'JDR',   CategoriaItem::ManoObra,    380.00, null],
            ['Jornada maestro de obra',          'JDR',   CategoriaItem::ManoObra,    900.00, 'Especialidad: estructuras'],
            ['Jornada fontanero',                'JDR',   CategoriaItem::ManoObra,    700.00, null],
            ['Jornada electricista',             'JDR',   CategoriaItem::ManoObra,    750.00, null],

            // ─── Herramienta y equipo ────────────────────────────────
            ['Hora-máquina mezcladora 1 saco',   'HM',    CategoriaItem::HerramientaEquipo,  85.00, 'Incluye combustible'],
            ['Hora-máquina vibrador concreto',   'HM',    CategoriaItem::HerramientaEquipo,  60.00, null],
            ['Alquiler andamio metálico/cuerpo', 'JDR',   CategoriaItem::HerramientaEquipo, 120.00, 'Por día'],
            ['Hora-máquina retroexcavadora',     'HM',    CategoriaItem::HerramientaEquipo, 950.00, 'Incluye operador y combustible'],

            // ─── Indirectos ──────────────────────────────────────────
            ['Transporte materiales <10 km',     'VIAJE', CategoriaItem::Indirectos,  450.00, 'Volqueta 7m³'],
            ['Supervisión técnica obra',         'JDR',   CategoriaItem::Indirectos, 1200.00, 'Ingeniero residente medio tiempo'],
        ];

        $creados = 0;
        $existentes = 0;

        foreach ($items as [$nombre, $unidadCodigo, $categoria, $precio, $observaciones]) {
            if (! isset($unidades[$unidadCodigo])) {
                $this->command->warn("Unidad {$unidadCodigo} no encontrada — saltando '{$nombre}'.");

                continue;
            }

            $existe = Item::where('zona_id', $zona->id)
                ->where('categoria', $categoria->value)
                ->whereRaw('UPPER(nombre) = ?', [mb_strtoupper($nombre, 'UTF-8')])
                ->exists();

            if ($existe) {
                $existentes++;

                continue;
            }

            // Código se autogenera vía el creating event del modelo
            Item::create([
                'zona_id'              => $zona->id,
                'unidad_medida_id'     => $unidades[$unidadCodigo],
                'categoria'            => $categoria,
                'nombre'               => $nombre,
                'precio_unitario'      => $precio,
                'observaciones_precio' => $observaciones,
                'activo'               => true,
            ]);

            $creados++;
        }

        $this->command->info("✓ ItemDemoSeeder: {$creados} items creados, {$existentes} ya existían.");
    }
}
