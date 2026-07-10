<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Material;
use App\Models\User;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo de inventario con visibilidad por bodega (Fases 1 y 2).
 *
 * Deja el sistema listo para PROBAR en el navegador:
 *  - Materiales + base de precios (vía ItemDemoSeeder).
 *  - DOS bodegas: Santa Rosa y San Pedro Sula.
 *  - El MISMO material (cemento) con stock en ambas a DISTINTO costo
 *    (L.200 en una, L.210 en otra) — demuestra el costo ponderado por
 *    bodega (Fase 1).
 *  - Un usuario BODEGUERO restringido a una sola bodega — demuestra la
 *    visibilidad por bodega (Fase 2): solo verá el stock de Santa Rosa.
 *
 * Idempotente: re-ejecutarlo no duplica stock ni usuarios.
 *
 * Ejecutar:
 *   php artisan db:seed --class=Database\\Seeders\\DemoInventarioBodegasSeeder
 */
class DemoInventarioBodegasSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Materiales físicos + base de precios por zona (idempotente).
        $this->call(ItemDemoSeeder::class);

        // Asegura los roles de inventario (idempotente).
        $this->call(RolesInventarioSeeder::class);

        // 2. Dos bodegas físicas.
        $santaRosa = Bodega::firstOrCreate(
            ['nombre' => 'BODEGA SANTA ROSA'],
            ['direccion' => 'SANTA ROSA DE COPÁN', 'responsable' => 'Carlos Mejía', 'activo' => true],
        );

        $sanPedro = Bodega::firstOrCreate(
            ['nombre' => 'BODEGA SAN PEDRO SULA'],
            ['direccion' => 'SAN PEDRO SULA, CORTÉS', 'responsable' => 'Ana Discua', 'activo' => true],
        );

        // 3. Materiales para el stock demo (creados por ItemDemoSeeder).
        $cemento = Material::query()->where('nombre', 'like', 'CEMENTO%')->first();
        $varilla = Material::query()->where('nombre', 'like', 'VARILLA%')->first();
        $arena = Material::query()->where('nombre', 'like', 'ARENA%')->first();

        // 4. Stock inicial. El MISMO cemento a distinto costo por bodega.
        if ($cemento !== null) {
            $this->stockInicial($cemento, $santaRosa, '100', '200'); // Santa Rosa @ 200
            $this->stockInicial($cemento, $sanPedro, '100', '210');  // San Pedro @ 210
        }

        if ($varilla !== null) {
            $this->stockInicial($varilla, $santaRosa, '50', '290');
            $this->stockInicial($varilla, $sanPedro, '40', '305');
        }

        if ($arena !== null) {
            $this->stockInicial($arena, $santaRosa, '30', '650');
        }

        // 5. Usuario BODEGUERO restringido a Santa Rosa (rol panel_user, SIN
        //    permiso VerTodasLasBodegas:Bodega). Solo verá el stock de su bodega.
        $bodeguero = User::updateOrCreate(
            ['email' => 'bodeguero@gmail.com'],
            [
                'name'              => 'Bodeguero Santa Rosa',
                'password'          => Hash::make('12345678'),
                'is_active'         => true,
                'email_verified_at' => now(),
            ],
        );

        $bodeguero->assignRole('bodeguero');
        $bodeguero->bodegas()->sync([$santaRosa->id]);

        // 6. Usuario GERENCIA que ve todas las bodegas.
        $gerente = User::updateOrCreate(
            ['email' => 'gerente@gmail.com'],
            [
                'name'              => 'Gerente General',
                'password'          => Hash::make('12345678'),
                'is_active'         => true,
                'email_verified_at' => now(),
            ],
        );

        $gerente->assignRole('gerencia');

        $this->command?->info('✓ Demo de inventario lista.');
        $this->command?->info("  Bodegas: {$santaRosa->codigo} (Santa Rosa) y {$sanPedro->codigo} (San Pedro Sula).");
        $this->command?->info('  Cemento: L.200 en Santa Rosa, L.210 en San Pedro (costo por bodega).');
        $this->command?->info('  Bodeguero (solo Santa Rosa): bodeguero@gmail.com / 12345678');
        $this->command?->info('  Gerencia (ve todo):          gerente@gmail.com / 12345678');
    }

    /**
     * Registra una entrada de stock si esa existencia aún no existe (para que
     * el seeder sea idempotente: re-correrlo no infla el inventario).
     */
    private function stockInicial(Material $material, Bodega $bodega, string $cantidad, string $costo): void
    {
        $yaExiste = Existencia::query()
            ->where('material_id', $material->id)
            ->where('bodega_id', $bodega->id)
            ->exists();

        if ($yaExiste) {
            return;
        }

        app(RegistrarMovimientoService::class)->entradaCompra(
            materialId: $material->id,
            destino: Ubicacion::bodega($bodega->id),
            cantidad: $cantidad,
            costoUnitario: $costo,
        );
    }
}
