<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Catalogos\VincularMaterialAItemService;
use Illuminate\Database\Seeder;

/**
 * Backfill: vincula items inventariables sin material_id a su Material
 * físico canónico (creándolo si no existe).
 *
 * Necesario en bases sembradas antes de que FichasConstructorasSeeder
 * vinculara materiales — sin el vínculo, esos items no aparecen en el
 * control presupuestario de materiales ni en requisiciones.
 *
 * Idempotente: correr N veces no duplica nada.
 */
class VincularMaterialesFaltantesSeeder extends Seeder
{
    public function run(VincularMaterialAItemService $servicio): void
    {
        $vinculados = $servicio->vincularFaltantes();

        $this->command->info("✓ VincularMaterialesFaltantesSeeder: {$vinculados} items vinculados a su material físico.");
    }
}
