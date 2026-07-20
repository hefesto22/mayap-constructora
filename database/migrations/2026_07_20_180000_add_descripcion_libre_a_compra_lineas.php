<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas LIBRES en compras (decisión Mauricio 2026-07-20): no hay
 * catálogo de repuestos — en las compras de taller/equipo/oficina cada
 * línea se escribe a mano (`descripcion`) sin material del catálogo.
 *
 * `material_id` pasa a nullable; el CHECK garantiza que toda línea
 * tiene O material del catálogo O descripción libre (al menos uno).
 * Las líneas libres NO generan movimientos de inventario (la exclusión
 * vive en ConfirmarCompraService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->foreignId('material_id')->nullable()->change();
            $table->string('descripcion')->nullable()->after('material_id');
        });

        DB::statement(
            'ALTER TABLE compra_lineas ADD CONSTRAINT compra_lineas_material_o_descripcion
             CHECK (material_id IS NOT NULL OR descripcion IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compra_lineas DROP CONSTRAINT IF EXISTS compra_lineas_material_o_descripcion');

        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->dropColumn('descripcion');
            $table->foreignId('material_id')->nullable(false)->change();
        });
    }
};
