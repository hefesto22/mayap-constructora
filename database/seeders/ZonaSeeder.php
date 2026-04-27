<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Zona;
use Illuminate\Database\Seeder;

/**
 * Zona inicial: Santa Rosa de Copán.
 *
 * MAYAP tiene su sede principal acá y es la zona donde se va a cargar
 * primero la base de precios. Otras zonas (Tegucigalpa, San Pedro Sula,
 * etc.) las agrega el usuario desde Filament según expanda operaciones.
 *
 * Idempotente vía firstOrCreate.
 */
class ZonaSeeder extends Seeder
{
    public function run(): void
    {
        Zona::firstOrCreate(
            ['codigo' => 'SRC'],
            [
                'nombre'      => 'Santa Rosa de Copán',
                'descripcion' => 'Zona principal — sede de operaciones',
                'activa'      => true,
            ]
        );
    }
}
