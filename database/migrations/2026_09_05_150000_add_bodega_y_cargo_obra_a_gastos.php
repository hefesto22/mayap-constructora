<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DE DÓNDE SALIÓ Y CUÁNTO SE LE CARGA A LA OBRA (Mauricio, 2026-09-05).
 *
 * "Si se compró o se sacó del inventario; si se sacó de bodega que diga
 * qué fue lo que se usó, y la opción de cargar a obra que coloque el
 * monto de cuánto se le cobrará a esa obra o si se reporta como gasto."
 *
 *  - material_id / bodega_id / cantidad: el repuesto salió de bodega. El
 *    monto NO se escribe — sale del costo promedio del inventario, y el
 *    movimiento baja la existencia de verdad.
 *  - monto_obra + tipo_cargo_obra: cargar a la obra deja de ser un sí/no.
 *    Se dice CUÁNTO y en qué concepto: como gasto de la obra (entra a su
 *    costo) o como cobro (se le factura). El costo real de la máquina
 *    sigue siendo `monto`: cobrar más no abarata la reparación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gastos_mantenimiento', function (Blueprint $table): void {
            $table->foreignId('material_id')->nullable()->after('proyecto_id')
                ->constrained('materiales')->nullOnDelete();
            $table->foreignId('bodega_id')->nullable()->after('material_id')
                ->constrained('bodegas')->nullOnDelete();
            $table->decimal('cantidad', 14, 4)->nullable()->after('bodega_id');

            $table->decimal('monto_obra', 14, 2)->nullable()->after('cargar_a_proyecto');
            $table->string('tipo_cargo_obra', 20)->nullable()->after('monto_obra');
        });

        DB::statement('ALTER TABLE gastos_mantenimiento DROP CONSTRAINT IF EXISTS gasto_mantenimiento_valido');

        DB::statement(
            "ALTER TABLE gastos_mantenimiento ADD CONSTRAINT gasto_mantenimiento_valido
             CHECK (
                 monto >= 0
                 AND (monto_obra IS NULL OR monto_obra >= 0)
                 AND origen IN ('encargado', 'recepcion', 'bodega')
                 AND (cargar_a_proyecto = false OR proyecto_id IS NOT NULL)
                 -- Cargarlo a la obra exige decir EN QUÉ CONCEPTO.
                 AND (cargar_a_proyecto = false OR tipo_cargo_obra IN ('gasto', 'cobro'))
                 -- Lo que salió de bodega sabe QUÉ salió y CUÁNTO.
                 AND (
                     origen <> 'bodega'
                     OR (material_id IS NOT NULL AND cantidad IS NOT NULL AND cantidad > 0)
                 )
             )"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gastos_mantenimiento DROP CONSTRAINT IF EXISTS gasto_mantenimiento_valido');
        DB::statement("DELETE FROM gastos_mantenimiento WHERE origen = 'bodega'");

        Schema::table('gastos_mantenimiento', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('material_id');
            $table->dropConstrainedForeignId('bodega_id');
            $table->dropColumn(['cantidad', 'monto_obra', 'tipo_cargo_obra']);
        });

        DB::statement(
            "ALTER TABLE gastos_mantenimiento ADD CONSTRAINT gasto_mantenimiento_valido
             CHECK (
                 monto >= 0
                 AND origen IN ('encargado', 'recepcion')
                 AND (cargar_a_proyecto = false OR proyecto_id IS NOT NULL)
             )"
        );
    }
};
