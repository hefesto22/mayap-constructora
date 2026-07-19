<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de renta de un proyecto tipo renta_maquinaria — el espejo
 * liviano de `proyecto_renglones`: aquí no hay fichas APU, hay
 * máquina × cantidad (horas o días) × tarifa = subtotal.
 *
 * TARIFA SNAPSHOT: se copia del catálogo de la máquina al crear la
 * línea (hora → tarifa_hora; día → tarifa_hora × jornada_horas) y es
 * ajustable al cotizar. Cambios posteriores del catálogo NO tocan
 * líneas existentes — lo pactado es lo pactado.
 *
 * LLEGADA: cada línea lleva SU fecha y hora de llegada (dos máquinas
 * del mismo proyecto pueden llegar días distintos). Al aprobar el
 * proyecto, estas fechas alimentan la agenda del calendario.
 *
 * EXTENSIONES: extender una renta = agregar una LÍNEA nueva marcada
 * es_extension (nunca se edita la línea original ya trabajada). El
 * historial de qué se pactó y cuándo queda completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyecto_lineas_renta', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')
                ->constrained('proyectos')
                ->cascadeOnDelete();

            $table->foreignId('maquina_id')
                ->constrained('maquinas')
                ->restrictOnDelete();

            $table->unsignedInteger('orden')->default(1);

            $table->string('unidad', 10)->default('hora');
            $table->decimal('cantidad', 10, 2);
            $table->decimal('tarifa_snapshot', 14, 2);
            $table->decimal('subtotal_cache', 14, 2)->default(0);

            $table->date('fecha_llegada');
            $table->time('hora_llegada')->nullable();

            $table->boolean('es_extension')->default(false);

            $table->string('notas', 255)->nullable();

            $table->timestamps();

            $table->index('proyecto_id');
            $table->index('maquina_id');
            $table->index('fecha_llegada');
        });

        DB::statement(
            "ALTER TABLE proyecto_lineas_renta ADD CONSTRAINT proyecto_lineas_renta_unidad_valida
             CHECK (unidad IN ('hora', 'dia'))"
        );

        DB::statement(
            'ALTER TABLE proyecto_lineas_renta ADD CONSTRAINT proyecto_lineas_renta_cantidad_positiva
             CHECK (cantidad > 0)'
        );

        DB::statement(
            'ALTER TABLE proyecto_lineas_renta ADD CONSTRAINT proyecto_lineas_renta_tarifa_no_negativa
             CHECK (tarifa_snapshot >= 0)'
        );

        // Subtotal coherente con cantidad × tarifa (margen 0.02 por
        // redondeo NUMERIC, igual que proyecto_renglones).
        DB::statement(
            'ALTER TABLE proyecto_lineas_renta ADD CONSTRAINT proyecto_lineas_renta_subtotal_coherente
             CHECK (ABS(subtotal_cache - (cantidad * tarifa_snapshot)) <= 0.02)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_lineas_renta');
    }
};
