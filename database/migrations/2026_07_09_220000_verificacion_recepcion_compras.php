<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase G2 — verificación de recepción de compras:
 *
 * 1. Nuevo estado `por_recibir` (recreado el CHECK): la compra registrada
 *    viaja; el stock entra hasta que el punto de llegada VERIFICA.
 * 2. Por línea: `cantidad_recibida` (lo contado contra lo facturado) y
 *    quién/cuándo verificó. Diferencia facturado vs recibido = reclamo
 *    al proveedor, visible para siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_estado_valido');
        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_estado_valido
             CHECK (estado IN ('borrador', 'por_recibir', 'confirmada', 'anulada'))"
        );

        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->decimal('cantidad_recibida', 12, 4)->nullable();
            $table->timestamp('verificada_at')->nullable();
            $table->foreignId('verificada_por')->nullable()->constrained('users')->nullOnDelete();
        });

        // Coherencia: línea verificada SIEMPRE con cantidad y fecha juntas.
        DB::statement(
            'ALTER TABLE compra_lineas ADD CONSTRAINT compra_lineas_verificacion_coherente
             CHECK (
                (verificada_at IS NULL AND cantidad_recibida IS NULL)
                OR (verificada_at IS NOT NULL AND cantidad_recibida IS NOT NULL AND cantidad_recibida >= 0)
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compra_lineas DROP CONSTRAINT IF EXISTS compra_lineas_verificacion_coherente');

        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verificada_por');
            $table->dropColumn(['cantidad_recibida', 'verificada_at']);
        });

        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_estado_valido');
        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_estado_valido
             CHECK (estado IN ('borrador', 'confirmada', 'anulada'))"
        );
    }
};
