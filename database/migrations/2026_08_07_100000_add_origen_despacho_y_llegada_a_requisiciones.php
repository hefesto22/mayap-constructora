<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Origen del despacho + seguimiento de llegada en la requisición
 * (decisión Mauricio 2026-08-07, a raíz del bug de REQ-2026-00005: una
 * compra directa a obra terminó "En tránsito" DESPUÉS de estar despachada,
 * cuando ese material nunca viajó desde ninguna bodega nuestra).
 *
 * - `origen_despacho`: bodega | compra_directa. NULL mientras no se
 *   despacha. Es lo que hace que la máquina de estados ofrezca EnTransito
 *   (vía bodega) o la recepción directa (vía compra). Sin default de BD a
 *   propósito: NULL significa "todavía no se decidió", no "bodega".
 * - `fecha_estimada_llegada`: copia denormalizada de la fecha que el
 *   proveedor prometió en la compra enlazada. Vive acá para que el listado
 *   la ordene y la pinte sin N+1; el ÚNICO escritor es
 *   SeguimientoLlegadaRequisicionService.
 * - `aviso_llegada_obra_at`: idempotencia de los avisos a la OBRA, aparte
 *   de `compras.aviso_llegada_at` (que es el de la oficina) para que un
 *   aviso no se coma al otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisiciones', function (Blueprint $table): void {
            $table->string('origen_despacho', 20)->nullable()->after('estado');
            $table->date('fecha_estimada_llegada')->nullable()->after('fecha_necesaria');
            $table->timestamp('aviso_llegada_obra_at')->nullable()->after('fecha_estimada_llegada');

            $table->index(['estado', 'fecha_estimada_llegada'], 'requisiciones_seguimiento_llegada_idx');
        });

        DB::statement(
            "ALTER TABLE requisiciones ADD CONSTRAINT requisiciones_origen_despacho_valido
             CHECK (origen_despacho IS NULL OR origen_despacho IN ('bodega', 'compra_directa'))"
        );

        // ── Backfill ────────────────────────────────────────────────────
        // Las requisiciones que YA pasaron por el despacho necesitan su
        // origen, o la máquina de estados no sabría qué ofrecerles. Vino
        // por compra la que tiene una compra directa a obra enlazada y
        // efectivamente confirmada; el resto salió de bodega.
        DB::statement(
            "UPDATE requisiciones SET origen_despacho = 'compra_directa'
              WHERE estado IN ('despachada', 'en_transito', 'recibida', 'cerrada', 'discrepancia')
                AND EXISTS (
                    SELECT 1 FROM compras
                     WHERE compras.requisicion_id = requisiciones.id
                       AND compras.proyecto_id IS NOT NULL
                       AND compras.estado IN ('confirmada', 'completada')
                )"
        );

        DB::statement(
            "UPDATE requisiciones SET origen_despacho = 'bodega'
              WHERE origen_despacho IS NULL
                AND estado IN ('despachada', 'en_transito', 'recibida', 'cerrada', 'discrepancia')"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE requisiciones DROP CONSTRAINT IF EXISTS requisiciones_origen_despacho_valido');

        Schema::table('requisiciones', function (Blueprint $table): void {
            $table->dropIndex('requisiciones_seguimiento_llegada_idx');
            $table->dropColumn(['origen_despacho', 'fecha_estimada_llegada', 'aviso_llegada_obra_at']);
        });
    }
};
