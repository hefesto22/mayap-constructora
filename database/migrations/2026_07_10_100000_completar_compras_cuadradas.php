<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre de compras — estado `completada`:
 *
 * Cuando TODO cuadró (facturado = recibido en cada línea) y pasó la ventana
 * de corrección (24 h desde el último conteo), el creador de la compra la
 * COMPLETA: queda sellada — sin corregir, sin anular, sin editar. Es la
 * conciliación final del documento contra lo físico.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_estado_valido');
        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_estado_valido
             CHECK (estado IN ('borrador', 'por_recibir', 'confirmada', 'completada', 'anulada'))"
        );

        Schema::table('compras', function (Blueprint $table): void {
            $table->timestamp('completada_at')->nullable();
            $table->foreignId('completada_por')->nullable()->constrained('users')->nullOnDelete();
        });

        // Coherencia: completada SIEMPRE con su fecha; ningún otro estado la lleva.
        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_completada_coherente
             CHECK (
                (estado = 'completada' AND completada_at IS NOT NULL)
                OR (estado <> 'completada' AND completada_at IS NULL)
             )"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_completada_coherente');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('completada_por');
            $table->dropColumn('completada_at');
        });

        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_estado_valido');
        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_estado_valido
             CHECK (estado IN ('borrador', 'por_recibir', 'confirmada', 'anulada'))"
        );
    }
};
