<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `partes_trabajo` — registro de horas trabajadas de una máquina en una
 * obra (vía su asignación). Es el documento que genera el cobro por horas.
 *
 * Captura por horómetro (lecturas inicial/final) o manual (horas directas).
 * La `tarifa_hora_aplicada` y el `costo_cache` se congelan al registrar, así
 * el costo histórico no cambia aunque luego cambie la tarifa de la asignación.
 *
 * Auto-código PART-{AÑO}-##### en el modelo. Montos en NUMERIC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partes_trabajo', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();

            $table->foreignId('asignacion_maquina_id')
                ->constrained('asignaciones_maquina')
                ->restrictOnDelete();

            $table->date('fecha');

            $table->string('metodo_captura', 20);

            // Solo para método horómetro.
            $table->decimal('lectura_inicial', 10, 2)->nullable();
            $table->decimal('lectura_final', 10, 2)->nullable();

            $table->decimal('horas', 8, 2);
            $table->decimal('horas_extra', 8, 2)->default(0);
            $table->text('motivo_horas_extra')->nullable();

            // Snapshot de la tarifa y costo calculado (horas × tarifa).
            $table->decimal('tarifa_hora_aplicada', 12, 2);
            $table->decimal('costo_cache', 12, 2);

            $table->string('operador', 150)->nullable();
            $table->text('notas')->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('asignacion_maquina_id');
            $table->index('fecha');
            $table->index(['asignacion_maquina_id', 'fecha']);
        });

        DB::statement(
            "ALTER TABLE partes_trabajo ADD CONSTRAINT partes_trabajo_metodo_valido
             CHECK (metodo_captura IN ('horometro', 'manual'))"
        );

        DB::statement(
            'ALTER TABLE partes_trabajo ADD CONSTRAINT partes_trabajo_horas_positivas
             CHECK (horas > 0)'
        );

        DB::statement(
            'ALTER TABLE partes_trabajo ADD CONSTRAINT partes_trabajo_horas_extra_no_negativas
             CHECK (horas_extra >= 0)'
        );

        DB::statement(
            'ALTER TABLE partes_trabajo ADD CONSTRAINT partes_trabajo_costo_no_negativo
             CHECK (costo_cache >= 0 AND tarifa_hora_aplicada >= 0)'
        );

        // Coherencia de lecturas según el método de captura.
        DB::statement(
            "ALTER TABLE partes_trabajo ADD CONSTRAINT partes_trabajo_lecturas_coherentes
             CHECK (
                (metodo_captura = 'manual'
                    AND lectura_inicial IS NULL AND lectura_final IS NULL)
                OR
                (metodo_captura = 'horometro'
                    AND lectura_inicial IS NOT NULL AND lectura_final IS NOT NULL
                    AND lectura_final >= lectura_inicial)
             )"
        );

        // Horas extra exigen motivo.
        DB::statement(
            'ALTER TABLE partes_trabajo ADD CONSTRAINT partes_trabajo_motivo_horas_extra
             CHECK (horas_extra = 0 OR motivo_horas_extra IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('partes_trabajo');
    }
};
