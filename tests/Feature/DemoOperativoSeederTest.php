<?php

declare(strict_types=1);

use App\Models\CuentaPorCobrar;
use App\Models\CuentaPorPagar;
use App\Models\Proyecto;
use App\Services\Reportes\CostoProyectoService;
use Database\Seeders\DemoOperativoSeeder;

/*
|--------------------------------------------------------------------------
| Test de integración: el seeder de demo recorre el sistema de punta a punta.
|--------------------------------------------------------------------------
*/

test('el seeder de demo crea una obra con costo en las tres fuentes', function (): void {
    $this->seed(DemoOperativoSeeder::class);

    $obra = Proyecto::query()->where('nombre', 'like', 'PAVIMENTACIÓN%')->firstOrFail();

    $costo = (new CostoProyectoService)->calcular($obra);

    expect((float) $costo->costoMateriales)->toBeGreaterThan(0)
        ->and((float) $costo->costoMaquinaria)->toBeGreaterThan(0)
        ->and((float) $costo->costoManoObra)->toBeGreaterThan(0)
        ->and((float) $costo->costoTotal)->toBeGreaterThan(0)
        ->and((float) $costo->presupuesto)->toBe(500000.0);

    // El lazo de dinero también quedó poblado.
    expect(CuentaPorPagar::query()->count())->toBeGreaterThan(0)
        ->and(CuentaPorCobrar::query()->count())->toBeGreaterThan(0);
});
