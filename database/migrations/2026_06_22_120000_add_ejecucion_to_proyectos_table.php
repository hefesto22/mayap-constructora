<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende `proyectos` con la FASE DE EJECUCIÓN (obra) más allá de la
 * cotización: anticipo, plazo, fechas de inicio/fin, motivos de
 * pausa/cancelación y cache de avance físico.
 *
 * Estados nuevos (ver enum EstadoProyecto): en_ejecucion, pausada,
 * finalizada, cancelada. Se recrea el CHECK de estado para admitirlos.
 *
 * COHERENCIA por CHECK constraints:
 *  - estado válido dentro de los 9 valores.
 *  - si estado ∈ {en_ejecucion, pausada, finalizada} ⇒ fecha_inicio NOT NULL.
 *  - modo_plazo ∈ {calendario, habiles} o NULL.
 *  - plazo_dias > 0 o NULL.
 *  - anticipo_monto >= 0 o NULL.
 *  - avance_fisico_cache entre 0 y 100.
 *  - fecha_fin_estimada >= fecha_inicio (cuando ambas existen).
 *  - fecha_fin_real >= fecha_inicio (cuando ambas existen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            // ─── Anticipo / depósito del cliente ───
            $table->decimal('anticipo_monto', 14, 2)->nullable()->after('total_cache');
            $table->date('anticipo_fecha')->nullable()->after('anticipo_monto');
            $table->boolean('anticipo_recibido')->default(false)->after('anticipo_fecha');

            // ─── Plazo de ejecución ───
            $table->string('modo_plazo', 12)->nullable()->after('anticipo_recibido');
            $table->unsignedInteger('plazo_dias')->nullable()->after('modo_plazo');
            $table->date('fecha_inicio')->nullable()->after('plazo_dias');
            $table->date('fecha_fin_estimada')->nullable()->after('fecha_inicio');
            $table->date('fecha_fin_real')->nullable()->after('fecha_fin_estimada');

            // ─── Motivos de cambio de estado ───
            $table->text('motivo_pausa')->nullable()->after('fecha_fin_real');
            $table->text('motivo_cancelacion')->nullable()->after('motivo_pausa');

            // ─── Avance físico de obra (% derivado de actividades) ───
            $table->decimal('avance_fisico_cache', 5, 2)->default(0)->after('motivo_cancelacion');

            $table->index('fecha_inicio');
            $table->index('fecha_fin_estimada');
        });

        // Recrear el CHECK de estado para admitir los 9 valores.
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_estado_valido');
        DB::statement(
            "ALTER TABLE proyectos ADD CONSTRAINT proyectos_estado_valido
             CHECK (estado IN (
                'borrador', 'enviada', 'aprobada', 'rechazada', 'vencida',
                'en_ejecucion', 'pausada', 'finalizada', 'cancelada'
             ))"
        );

        // Si la obra arrancó (en ejecución / pausada / finalizada),
        // DEBE tener fecha de inicio.
        DB::statement(
            "ALTER TABLE proyectos ADD CONSTRAINT proyectos_inicio_requerido
             CHECK (
                estado NOT IN ('en_ejecucion', 'pausada', 'finalizada')
                OR fecha_inicio IS NOT NULL
             )"
        );

        // Modo de plazo válido.
        DB::statement(
            "ALTER TABLE proyectos ADD CONSTRAINT proyectos_modo_plazo_valido
             CHECK (modo_plazo IS NULL OR modo_plazo IN ('calendario', 'habiles'))"
        );

        // Plazo positivo.
        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_plazo_positivo
             CHECK (plazo_dias IS NULL OR plazo_dias > 0)'
        );

        // Anticipo no negativo.
        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_anticipo_no_negativo
             CHECK (anticipo_monto IS NULL OR anticipo_monto >= 0)'
        );

        // Avance físico en rango 0..100.
        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_avance_valido
             CHECK (avance_fisico_cache >= 0 AND avance_fisico_cache <= 100)'
        );

        // Fechas de fin coherentes con el inicio.
        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_fin_estimada_coherente
             CHECK (
                fecha_fin_estimada IS NULL
                OR fecha_inicio IS NULL
                OR fecha_fin_estimada >= fecha_inicio
             )'
        );

        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_fin_real_coherente
             CHECK (
                fecha_fin_real IS NULL
                OR fecha_inicio IS NULL
                OR fecha_fin_real >= fecha_inicio
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_fin_real_coherente');
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_fin_estimada_coherente');
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_avance_valido');
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_anticipo_no_negativo');
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_plazo_positivo');
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_modo_plazo_valido');
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_inicio_requerido');

        // Restaurar el CHECK de estado original (5 valores comerciales).
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_estado_valido');
        DB::statement(
            "ALTER TABLE proyectos ADD CONSTRAINT proyectos_estado_valido
             CHECK (estado IN ('borrador', 'enviada', 'aprobada', 'rechazada', 'vencida'))"
        );

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropIndex(['fecha_inicio']);
            $table->dropIndex(['fecha_fin_estimada']);
            $table->dropColumn([
                'anticipo_monto',
                'anticipo_fecha',
                'anticipo_recibido',
                'modo_plazo',
                'plazo_dias',
                'fecha_inicio',
                'fecha_fin_estimada',
                'fecha_fin_real',
                'motivo_pausa',
                'motivo_cancelacion',
                'avance_fisico_cache',
            ]);
        });
    }
};
