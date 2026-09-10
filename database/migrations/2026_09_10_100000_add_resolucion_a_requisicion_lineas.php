<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RESOLUCIÓN POR RENGLÓN (Mauricio, 2026-09-10).
 *
 * "Si el de obra pide x materiales el bodeguero confirma si hay en bodega
 * y salen de ahí; si no hay y se compraron, le llegarán; si no hay y no
 * se pudo comprar, se marca que ese no le llegará."
 *
 * Hasta hoy la decisión era del pedido completo: un solo material sin
 * stock mandaba TODA la requisición a "requisición de compra" y la obra
 * se quedaba esperando lo que sí había. Estas dos columnas bajan la
 * decisión al renglón:
 *
 *  - resolucion: bodega | comprar | no_disponible.
 *  - resolucion_nota: por qué no se consiguió (lo único que el de la obra
 *    va a querer leer cuando vea que ese material no le llega).
 *
 * El CHECK amarra la única combinación que sería mentira: decir "no se
 * consiguió" sin decir por qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisicion_lineas', function (Blueprint $table): void {
            $table->string('resolucion', 20)->nullable()->after('cantidad_recibida');
            $table->text('resolucion_nota')->nullable()->after('resolucion');
            $table->timestamp('resuelta_at')->nullable()->after('resolucion_nota');
            $table->foreignId('resuelta_por')->nullable()->after('resuelta_at')
                ->constrained('users')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE requisicion_lineas ADD CONSTRAINT requisicion_linea_resolucion_valida
             CHECK (resolucion IS NULL OR resolucion IN ('bodega', 'comprar', 'no_disponible'))"
        );

        // "No le llega" sin motivo es dejar a la obra adivinando.
        DB::statement(
            "ALTER TABLE requisicion_lineas ADD CONSTRAINT requisicion_linea_no_disponible_con_motivo
             CHECK (resolucion <> 'no_disponible' OR resolucion_nota IS NOT NULL)"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE requisicion_lineas DROP CONSTRAINT IF EXISTS requisicion_linea_no_disponible_con_motivo');
        DB::statement('ALTER TABLE requisicion_lineas DROP CONSTRAINT IF EXISTS requisicion_linea_resolucion_valida');

        Schema::table('requisicion_lineas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resuelta_por');
            $table->dropColumn(['resolucion', 'resolucion_nota', 'resuelta_at']);
        });
    }
};
