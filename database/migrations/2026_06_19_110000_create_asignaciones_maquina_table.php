<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `asignaciones_maquina` — vincula una máquina con una obra (proyecto)
 * durante un período, fijando la tarifa por hora pactada para ese trabajo.
 *
 * La tarifa se "congela" aquí (snapshot): aunque luego cambie la tarifa por
 * defecto de la máquina, los partes de esta asignación cobran la pactada.
 *
 * REGLA: una máquina solo puede tener UNA asignación activa a la vez
 * (índice único parcial). Auto-código ASMQ-##### en el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones_maquina', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();

            $table->foreignId('maquina_id')
                ->constrained('maquinas')
                ->restrictOnDelete();

            $table->foreignId('proyecto_id')
                ->constrained('proyectos')
                ->restrictOnDelete();

            // Tarifa pactada para esta obra (snapshot de la tarifa de la máquina
            // o la negociada para este proyecto).
            $table->decimal('tarifa_hora_pactada', 12, 2);

            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();

            $table->string('estado', 20)->default('activa');

            $table->text('notas')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // FK con índice explícito (regla del proyecto).
            $table->index('maquina_id');
            $table->index('proyecto_id');
            $table->index(['proyecto_id', 'estado']);
        });

        DB::statement(
            "ALTER TABLE asignaciones_maquina ADD CONSTRAINT asignaciones_maquina_estado_valido
             CHECK (estado IN ('activa', 'finalizada'))"
        );

        DB::statement(
            'ALTER TABLE asignaciones_maquina ADD CONSTRAINT asignaciones_maquina_tarifa_no_negativa
             CHECK (tarifa_hora_pactada >= 0)'
        );

        DB::statement(
            'ALTER TABLE asignaciones_maquina ADD CONSTRAINT asignaciones_maquina_fechas_coherentes
             CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio)'
        );

        // Una sola asignación activa por máquina (ignora finalizadas y borradas).
        DB::statement(
            "CREATE UNIQUE INDEX asignaciones_maquina_una_activa
             ON asignaciones_maquina (maquina_id)
             WHERE estado = 'activa' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaciones_maquina');
    }
};
