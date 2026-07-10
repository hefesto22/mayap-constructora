<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compra con entrega directa a obra (drop-shipping de ferretería).
 *
 * El destino de una compra ahora es bodega XOR obra:
 *  - bodega_id  → entrada clásica a inventario de bodega (reabastecimiento)
 *  - proyecto_id → el material va directo a la obra; el costo se imputa al
 *    proyecto al precio real de factura sin pasar por bodega.
 *
 * `requisicion_id` enlaza la compra con la requisición que la originó:
 *  - trazabilidad (qué pedido de obra provocó esta compra)
 *  - si la compra es directa a obra, al confirmarse marca las líneas de la
 *    requisición como despachadas (despacho directo).
 *
 * CHECK constraint garantiza exactamente UN destino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            $table->foreignId('proyecto_id')
                ->nullable()
                ->after('bodega_id')
                ->comment('Obra destino en compra directa (XOR con bodega_id).')
                ->constrained('proyectos')
                ->restrictOnDelete();

            $table->foreignId('requisicion_id')
                ->nullable()
                ->after('proyecto_id')
                ->comment('Requisición que originó la compra (trazabilidad / despacho directo).')
                ->constrained('requisiciones')
                ->restrictOnDelete();

            $table->index(['proyecto_id', 'estado']);
            $table->index('requisicion_id');
        });

        // El destino clásico (bodega) deja de ser obligatorio…
        DB::statement('ALTER TABLE compras ALTER COLUMN bodega_id DROP NOT NULL');

        // …pero SIEMPRE hay exactamente un destino: bodega XOR obra.
        DB::statement(<<<'SQL'
            ALTER TABLE compras
            ADD CONSTRAINT compras_destino_unico_check
            CHECK (((bodega_id IS NOT NULL)::int + (proyecto_id IS NOT NULL)::int) = 1)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_destino_unico_check');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requisicion_id');
            $table->dropConstrainedForeignId('proyecto_id');
        });

        // Solo restaurable si no existen compras directas a obra.
        DB::statement('ALTER TABLE compras ALTER COLUMN bodega_id SET NOT NULL');
    }
};
