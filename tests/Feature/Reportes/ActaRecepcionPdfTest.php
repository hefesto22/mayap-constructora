<?php

declare(strict_types=1);

use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Material;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Compras\MarcarPorRecibirService;
use App\Services\Compras\VerificarRecepcionService;
use App\Services\Reportes\ActaRecepcionPdfService;
use App\Support\Permisos;
use App\Support\Roles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Acta de recepción — el soporte físico del reclamo al proveedor.
|--------------------------------------------------------------------------
*/

test('el acta detalla facturado vs recibido, la diferencia y quién verificó', function (): void {
    Role::firstOrCreate(['name' => Roles::BODEGUERO, 'guard_name' => 'web']);
    Permission::findOrCreate(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO, 'web');
    Permission::findOrCreate(Permisos::VERIFICAR_RECEPCION_COMPRA, 'web');
    Role::findByName(Roles::BODEGUERO, 'web')->givePermissionTo(Permisos::VERIFICAR_RECEPCION_COMPRA);

    $bodega = Bodega::factory()->create(['nombre' => 'BODEGA SANTA ROSA']);
    $material = Material::factory()->create(['nombre' => 'TUBERIA PVC 6']);
    $proveedor = Proveedor::factory()->create(['nombre' => 'FERRETERIA UNO']);

    $bodeguero = User::factory()->create(['is_active' => true, 'name' => 'DON CHEPE']);
    $bodeguero->assignRole(Roles::BODEGUERO);
    $bodeguero->bodegas()->attach($bodega);

    $compra = Compra::factory()
        ->paraBodega($bodega)
        ->paraProveedor($proveedor)
        ->create(['aplica_isv' => false, 'isv_porcentaje' => 0, 'numero_factura' => 'F-778899']);
    $linea = CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $material->id,
        'cantidad'  => 60, 'costo_unitario' => 169.57,
    ]);

    app(MarcarPorRecibirService::class)->registrar($compra);

    // Llegaron 55 de 60: diferencia de -5 para el reclamo.
    app(VerificarRecepcionService::class)->verificar($compra->fresh(), [$linea->id => '55'], $bodeguero);

    $html = app(ActaRecepcionPdfService::class)->construirHtml($compra->fresh());

    expect($html)
        ->toContain('Acta de recepción')
        ->toContain($compra->codigo)
        ->toContain('FERRETERIA UNO')
        ->toContain('F-778899')
        ->toContain('RECEPCIÓN CON DIFERENCIAS')
        ->toContain('TUBERIA PVC 6')
        ->toContain('60.00')      // facturado
        ->toContain('55.00')      // recibido
        ->toContain('-5.00')      // diferencia (el reclamo)
        ->toContain('DON CHEPE'); // quién verificó
});

test('el acta es PARCIAL para el encargado (solo su porción, sin total); completa para gerencia', function (): void {
    Role::firstOrCreate(['name' => Roles::ENCARGADO_OBRA, 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Roles::GERENCIA, 'guard_name' => 'web']);

    $bodega = Bodega::factory()->create(['nombre' => 'BODEGA CENTRAL']);
    $obra = Proyecto::factory()->enEjecucion()->create();

    $tuberia = Material::factory()->create(['nombre' => 'TUBERIA PVC 6']);
    $kit = Material::factory()->create(['nombre' => 'KIT DE CONEXION']);

    $compra = Compra::factory()->paraBodega($bodega)->create(['aplica_isv' => false, 'isv_porcentaje' => 0]);
    CompraLinea::factory()->create([
        'compra_id' => $compra->id, 'material_id' => $tuberia->id,
        'cantidad'  => 60, 'costo_unitario' => 100,
    ]);
    CompraLinea::factory()->create([
        'compra_id'   => $compra->id, 'material_id' => $kit->id,
        'cantidad'    => 10, 'costo_unitario' => 100,
        'proyecto_id' => $obra->id,
    ]);

    $eo = User::factory()->create(['is_active' => true]);
    $eo->assignRole(Roles::ENCARGADO_OBRA);
    $obra->encargados()->attach($eo->id);

    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->assignRole(Roles::GERENCIA);

    $servicio = app(ActaRecepcionPdfService::class);

    // Encargado: SOLO su porción de obra, con banner y SIN total facturado.
    $parcial = $servicio->construirHtml($compra->fresh(), $eo);

    expect($parcial)
        ->toContain('ACTA PARCIAL')
        ->toContain('KIT DE CONEXION')
        ->not->toContain('TUBERIA PVC 6')
        ->not->toContain('Total facturado');

    // Gerencia: el documento completo con las dos porciones y el total.
    $completa = $servicio->construirHtml($compra->fresh(), $gerente);

    expect($completa)
        ->toContain('TUBERIA PVC 6')
        ->toContain('KIT DE CONEXION')
        ->toContain('Total facturado')
        ->not->toContain('ACTA PARCIAL');
});
