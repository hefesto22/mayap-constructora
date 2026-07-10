<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para ANULAR compras confirmadas:
 *
 * 1. Nuevo tipo en el libro mayor: `anulacion_compra` (reversa del stock
 *    al valor exacto que la entrada metió) — recrea el CHECK de tipo.
 * 2. Auditoría de la anulación en `compras`: motivo obligatorio, cuándo
 *    y quién (además de la bitácora de activitylog).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE movimientos_inventario DROP CONSTRAINT IF EXISTS movimientos_tipo_valido');
        DB::statement(
            "ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_tipo_valido
             CHECK (tipo IN (
                'entrada_compra', 'salida_despacho', 'traslado', 'consumo_obra',
                'devolucion', 'ajuste_positivo', 'ajuste_negativo', 'anulacion_compra'
             ))"
        );

        Schema::table('compras', function (Blueprint $table): void {
            $table->text('motivo_anulacion')->nullable();
            $table->timestamp('anulada_at')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users')->nullOnDelete();
        });

        // Coherencia: compra anulada SIEMPRE con motivo y fecha.
        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_anulacion_coherente
             CHECK (
                (estado = 'anulada' AND motivo_anulacion IS NOT NULL AND anulada_at IS NOT NULL)
                OR (estado <> 'anulada' AND motivo_anulacion IS NULL AND anulada_at IS NULL)
             )"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_anulacion_coherente');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('anulada_por');
            $table->dropColumn(['motivo_anulacion', 'anulada_at']);
        });

        DB::statement('ALTER TABLE movimientos_inventario DROP CONSTRAINT IF EXISTS movimientos_tipo_valido');
        DB::statement(
            "ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_tipo_valido
             CHECK (tipo IN (
                'entrada_compra', 'salida_despacho', 'traslado', 'consumo_obra',
                'devolucion', 'ajuste_positivo', 'ajuste_negativo'
             ))"
        );
    }
};
