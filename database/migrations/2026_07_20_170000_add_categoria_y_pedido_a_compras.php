<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías de compra + seguimiento de pedidos (decisión Mauricio
 * 2026-07-20):
 *
 * - `categoria`: materiales (el flujo original), taller/repuestos,
 *   equipo y construcción, oficina. Las compras existentes quedan como
 *   materiales (default del backfill).
 * - `fecha_estimada_llegada`: cuándo debería llegar un pedido (compras
 *   "por pedido" con días de espera); dispara la campanita ese día.
 * - `aviso_llegada_at`: marca idempotente del aviso; cambiar la fecha
 *   estimada la reinicia.
 * - `mantenimiento_id`: vínculo opcional de una compra de repuestos con
 *   el mantenimiento de la máquina (el gasto queda trazable y la fecha
 *   estimada alimenta la del mantenimiento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            $table->string('categoria', 30)->default('materiales')->after('estado');
            $table->date('fecha_estimada_llegada')->nullable()->after('fecha_recepcion');
            $table->timestamp('aviso_llegada_at')->nullable()->after('fecha_estimada_llegada');

            $table->foreignId('mantenimiento_id')->nullable()
                ->after('requisicion_id')
                ->constrained('mantenimientos_maquina')
                ->nullOnDelete();

            $table->index('categoria');
        });

        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_categoria_valida
             CHECK (categoria IN ('materiales', 'taller', 'equipo_construccion', 'oficina'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_categoria_valida');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mantenimiento_id');
            $table->dropIndex(['categoria']);
            $table->dropColumn(['categoria', 'fecha_estimada_llegada', 'aviso_llegada_at']);
        });
    }
};
