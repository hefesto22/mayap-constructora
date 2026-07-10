<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compras mixtas + costos de la factura completa.
 *
 * 1. DESTINO POR LÍNEA (con herencia de cabecera): una misma factura puede
 *    llevar 100 bolsas a la obra y 100 a bodega. Cada línea puede definir su
 *    propio destino (bodega XOR obra); si ambos son NULL hereda el destino
 *    de la cabecera. CHECK: a lo sumo UN destino por línea.
 *
 * 2. FLETE Y DESCUENTO GLOBALES (landed cost, NIC 2): `costo_envio` y
 *    `descuento` de la factura se PRORRATEAN entre líneas por su valor y
 *    capitalizan al costo del inventario/obra. La CxP incluye el flete
 *    (se le debe al proveedor) y descuenta el descuento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->foreignId('bodega_id')
                ->nullable()
                ->after('material_id')
                ->comment('Destino bodega de ESTA línea (null = hereda cabecera).')
                ->constrained('bodegas')
                ->restrictOnDelete();

            $table->foreignId('proyecto_id')
                ->nullable()
                ->after('bodega_id')
                ->comment('Destino obra de ESTA línea (null = hereda cabecera).')
                ->constrained('proyectos')
                ->restrictOnDelete();

            $table->index('proyecto_id');
        });

        // A lo sumo UN destino propio por línea (ambos null = herencia).
        DB::statement(<<<'SQL'
            ALTER TABLE compra_lineas
            ADD CONSTRAINT compra_lineas_destino_maximo_uno_check
            CHECK (((bodega_id IS NOT NULL)::int + (proyecto_id IS NOT NULL)::int) <= 1)
            SQL);

        Schema::table('compras', function (Blueprint $table): void {
            $table->decimal('costo_envio', 14, 2)
                ->default(0)
                ->after('isv_porcentaje')
                ->comment('Flete de la factura. Se prorratea a las líneas y capitaliza (landed cost).');

            $table->decimal('descuento', 14, 2)
                ->default(0)
                ->after('costo_envio')
                ->comment('Descuento global de la factura. Se prorratea restando.');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE compras
            ADD CONSTRAINT compras_envio_descuento_no_negativos_check
            CHECK (costo_envio >= 0 AND descuento >= 0)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_envio_descuento_no_negativos_check');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropColumn(['costo_envio', 'descuento']);
        });

        DB::statement('ALTER TABLE compra_lineas DROP CONSTRAINT IF EXISTS compra_lineas_destino_maximo_uno_check');

        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proyecto_id');
            $table->dropConstrainedForeignId('bodega_id');
        });
    }
};
