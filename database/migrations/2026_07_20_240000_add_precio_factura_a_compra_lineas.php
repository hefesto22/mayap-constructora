<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Precio unitario TAL CUAL factura por línea (decisión Mauricio
 * 2026-07-20): es la fuente de la verdad del total — el neto es un
 * derivado (precio / 1.15) y multiplicar netos redondeados arrastraba
 * centavos (100 × L 10.00 daban L 1,000.50 en vez de L 1,000.00).
 * Nullable: líneas viejas o capturadas tecleando el neto directo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->decimal('precio_factura', 16, 4)
                ->nullable()
                ->after('costo_unitario')
                ->comment('Precio unitario tal cual factura (con ISV si la línea es gravada); null = se capturó el neto directo');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE compra_lineas
            ADD CONSTRAINT compra_lineas_precio_factura_no_negativo
            CHECK (precio_factura IS NULL OR precio_factura >= 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compra_lineas DROP CONSTRAINT IF EXISTS compra_lineas_precio_factura_no_negativo');

        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->dropColumn('precio_factura');
        });
    }
};
