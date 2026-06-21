<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `mantenimientos_maquina` — registro de cada evento de mantenimiento
 * (avería/reparación) de una máquina.
 *
 * Si la avería ocurrió mientras la máquina trabajaba, se enlaza la asignación
 * que se finalizó (`asignacion_finalizada_id`) y, si hubo sustitución, la
 * nueva asignación de la máquina sustituta (`asignacion_sustituta_id`). Así
 * queda trazable "qué máquina reemplazó a cuál y en qué obra".
 *
 * Auto-código MANT-{AÑO}-##### en el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mantenimientos_maquina', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();

            $table->foreignId('maquina_id')
                ->constrained('maquinas')
                ->restrictOnDelete();

            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();

            $table->text('motivo');

            // Asignación que la avería cortó (si trabajaba al averiarse).
            $table->foreignId('asignacion_finalizada_id')->nullable()
                ->constrained('asignaciones_maquina')
                ->nullOnDelete();

            // Nueva asignación de la máquina sustituta (si hubo sustitución).
            $table->foreignId('asignacion_sustituta_id')->nullable()
                ->constrained('asignaciones_maquina')
                ->nullOnDelete();

            $table->string('estado', 20)->default('en_proceso');

            $table->text('notas')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('maquina_id');
            $table->index('estado');
            $table->index(['maquina_id', 'estado']);
        });

        DB::statement(
            "ALTER TABLE mantenimientos_maquina ADD CONSTRAINT mantenimientos_maquina_estado_valido
             CHECK (estado IN ('en_proceso', 'finalizado'))"
        );

        DB::statement(
            'ALTER TABLE mantenimientos_maquina ADD CONSTRAINT mantenimientos_maquina_fechas_coherentes
             CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mantenimientos_maquina');
    }
};
