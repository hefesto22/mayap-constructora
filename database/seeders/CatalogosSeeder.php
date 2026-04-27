<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orquestador de seeders de catálogos base (Sprint 1).
 *
 * Orden importa: unidades primero (sin dependencias), luego zonas.
 * Items NO se seedean — los carga el usuario desde Filament.
 */
class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UnidadMedidaSeeder::class,
            ZonaSeeder::class,
        ]);
    }
}
