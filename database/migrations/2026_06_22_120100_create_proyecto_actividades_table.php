<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `proyecto_actividades` — checklist de avance físico de la obra.
 *
 * Cada actividad es un hito/tarea que se marca como completada
 * (ej: "ARRANQUE PLANTEL", "EXCAVACIÓN", "FUNDICIÓN DE LOSA"). El
 * porcentaje de avance del proyecto se deriva de estas actividades.
 *
 * PONDERACIÓN (`peso`): permite que unas actividades valgan más que
 * otras en el % total. Es OPCIONAL:
 *  - Si TODAS las actividades del proyecto tienen peso NULL → cada una
 *    vale lo mismo (avance = completadas / total).
 *  - Si tienen peso → avance = Σ peso(completadas) / Σ peso(todas).
 * El cálculo concreto vive en CalcularAvanceProyectoService.
 *
 * El avance resultante se cachea en `proyectos.avance_fisico_cache`
 * para no recalcular en cada listado.
 *
 * CASCADE: si se elimina el proyecto, sus actividades se eliminan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyecto_actividades', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('orden')->default(0);

            $table->string('nombre', 255);

            // Ponderación opcional en el % de avance. NULL = peso uniforme.
            $table->decimal('peso', 6, 2)->nullable();

            $table->boolean('completada')->default(false);
            $table->date('fecha_completada')->nullable();

            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index(['proyecto_id', 'orden']);
            $table->index(['proyecto_id', 'completada']);
        });

        // CHECK: peso no negativo.
        DB::statement(
            'ALTER TABLE proyecto_actividades ADD CONSTRAINT proyecto_actividades_peso_no_negativo
             CHECK (peso IS NULL OR peso >= 0)'
        );

        // CHECK: si está completada, debe tener fecha_completada; y
        // viceversa, sin completar no debe haber fecha (coherencia).
        DB::statement(
            'ALTER TABLE proyecto_actividades ADD CONSTRAINT proyecto_actividades_completada_coherente
             CHECK (
                (completada = TRUE AND fecha_completada IS NOT NULL)
                OR (completada = FALSE AND fecha_completada IS NULL)
             )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_actividades');
    }
};
