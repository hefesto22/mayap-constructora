<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\UnidadMedida;
use Illuminate\Database\Seeder;

/**
 * Catálogo base de unidades de medida usadas en construcción civil
 * en Honduras.
 *
 * El usuario puede agregar más desde Filament — esto es solo el
 * arranque para que un sistema recién instalado tenga lo común.
 *
 * `firstOrCreate` por código garantiza que correr el seeder múltiples
 * veces no genera duplicados ni errores (idempotente).
 */
class UnidadMedidaSeeder extends Seeder
{
    public function run(): void
    {
        $unidades = [
            // Áreas y volúmenes
            ['codigo' => 'M2',   'nombre' => 'Metro cuadrado',         'simbolo' => 'm²'],
            ['codigo' => 'M3',   'nombre' => 'Metro cúbico',           'simbolo' => 'm³'],
            ['codigo' => 'PIE2', 'nombre' => 'Pie cuadrado',           'simbolo' => 'ft²'],

            // Longitudes
            ['codigo' => 'ML',   'nombre' => 'Metro lineal',           'simbolo' => 'ml'],
            ['codigo' => 'VAR',  'nombre' => 'Varilla (6 m)',          'simbolo' => 'var'],

            // Pesos
            ['codigo' => 'KG',   'nombre' => 'Kilogramo',              'simbolo' => 'kg'],
            ['codigo' => 'LB',   'nombre' => 'Libra',                  'simbolo' => 'lb'],
            ['codigo' => 'QQ',   'nombre' => 'Quintal',                'simbolo' => 'qq'],

            // Volumen líquido
            ['codigo' => 'GLN',  'nombre' => 'Galón',                  'simbolo' => 'gal'],
            ['codigo' => 'LT',   'nombre' => 'Litro',                  'simbolo' => 'L'],

            // Empaques y unidades
            ['codigo' => 'BOLSA', 'nombre' => 'Bolsa',                 'simbolo' => null],
            ['codigo' => 'UND',   'nombre' => 'Unidad',                'simbolo' => 'u'],
            ['codigo' => 'VIAJE', 'nombre' => 'Viaje (volqueta)',      'simbolo' => null],

            // Mano de obra
            ['codigo' => 'JDR',   'nombre' => 'Jornada',               'simbolo' => null],
            ['codigo' => 'HH',    'nombre' => 'Hora-hombre',           'simbolo' => 'h'],

            // Equipo
            ['codigo' => 'HM',    'nombre' => 'Hora-máquina',          'simbolo' => 'h'],

            // Indirectos / globales
            ['codigo' => 'GBL',   'nombre' => 'Global',                'simbolo' => null],
        ];

        foreach ($unidades as $datos) {
            UnidadMedida::firstOrCreate(
                ['codigo' => $datos['codigo']],
                [
                    'nombre'  => $datos['nombre'],
                    'simbolo' => $datos['simbolo'],
                    'activo'  => true,
                ]
            );
        }
    }
}
