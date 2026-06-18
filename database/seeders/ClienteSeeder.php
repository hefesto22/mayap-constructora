<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cliente;
use Illuminate\Database\Seeder;

/**
 * Seeder demo con clientes representativos para hacer cotizaciones
 * de prueba en zona SRC y TGU.
 *
 * Mezcla: 3 empresas con RTN + 2 personas individuales sin RTN.
 *
 * Idempotente — usa firstOrCreate por nombre.
 *
 * NO se ejecuta automáticamente en DatabaseSeeder. Para cargarlo:
 *   php artisan db:seed --class=Database\\Seeders\\ClienteSeeder
 */
class ClienteSeeder extends Seeder
{
    public function run(): void
    {
        $clientes = [
            [
                'nombre'    => 'COMERCIAL HONDUREÑA S.A. DE C.V.',
                'rtn'       => '08019985012345',
                'telefono'  => '2552-3300',
                'email'     => 'contacto@comercialhondurena.hn',
                'direccion' => 'BARRIO EL CENTRO, FRENTE AL PARQUE CENTRAL',
                'ciudad'    => 'SANTA ROSA DE COPÁN',
                'notas'     => 'CLIENTE PREMIUM - PROYECTOS RECURRENTES',
            ],
            [
                'nombre'    => 'INVERSIONES MAYA S.A.',
                'rtn'       => '08011988054321',
                'telefono'  => '2238-1100',
                'email'     => 'gerencia@inversionesmaya.com',
                'direccion' => 'COLONIA PALMIRA, CALLE LA REFORMA',
                'ciudad'    => 'TEGUCIGALPA',
                'notas'     => null,
            ],
            [
                'nombre'    => 'CONSTRUCTORA OLIMPIA S. DE R.L.',
                'rtn'       => '05011990098765',
                'telefono'  => '2553-7700',
                'email'     => 'olimpia@constructora.hn',
                'direccion' => 'BARRIO EL TRAPICHE, CONTIGUO A FERRETERIA EL ÉXITO',
                'ciudad'    => 'SANTA ROSA DE COPÁN',
                'notas'     => 'PAGO 60 DÍAS DESPUÉS DE FACTURACIÓN',
            ],
            [
                'nombre'    => 'JUAN CARLOS PÉREZ MARTÍNEZ',
                'rtn'       => null,
                'telefono'  => '9988-7766',
                'email'     => 'juancperez@gmail.com',
                'direccion' => 'BARRIO MERCEDES, CALLE PRINCIPAL',
                'ciudad'    => 'SANTA ROSA DE COPÁN',
                'notas'     => 'CLIENTE INDIVIDUAL',
            ],
            [
                'nombre'    => 'MARIA ELENA RODRIGUEZ',
                'rtn'       => null,
                'telefono'  => '3322-1100',
                'email'     => 'maria.rodriguez@hotmail.com',
                'direccion' => 'COLONIA SAN MIGUEL, AVENIDA LOS PINOS',
                'ciudad'    => 'TEGUCIGALPA',
                'notas'     => null,
            ],
        ];

        $creados = 0;
        $existentes = 0;

        foreach ($clientes as $datos) {
            $existente = Cliente::where('nombre', mb_strtoupper($datos['nombre'], 'UTF-8'))->first();

            if ($existente !== null) {
                $existentes++;

                continue;
            }

            Cliente::create([
                ...$datos,
                'activo' => true,
            ]);

            $creados++;
        }

        $this->command->info(
            "✓ ClienteSeeder: {$creados} clientes creados, {$existentes} ya existían."
        );
    }
}
