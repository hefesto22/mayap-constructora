<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora del mantenimiento (decisión Mauricio 2026-07-20): historial
 * de diagnósticos y avances con FECHA Y HORA automáticas — cada entrada
 * registra en qué fase estaba la reparación, qué se encontró o hizo, y
 * quién lo registró. Solo se agregan entradas, nunca se editan.
 *
 * `created_at` ES la fecha y hora del diagnóstico/avance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacoras_mantenimiento', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('mantenimiento_maquina_id')
                ->constrained('mantenimientos_maquina')
                ->restrictOnDelete();

            $table->string('fase', 30);
            $table->text('detalle');

            $table->foreignId('user_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('mantenimiento_maquina_id');
        });

        DB::statement(
            "ALTER TABLE bitacoras_mantenimiento ADD CONSTRAINT bitacoras_mantenimiento_fase_valida
             CHECK (fase IN ('diagnostico', 'sin_repuestos', 'compra_repuestos', 'reparacion'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacoras_mantenimiento');
    }
};
