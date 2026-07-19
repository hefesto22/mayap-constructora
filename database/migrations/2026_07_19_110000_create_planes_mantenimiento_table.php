<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de mantenimiento preventivo por máquina (decisión Mauricio
 * 2026-07-19): cambio de aceite, puntas, cuchillas o lo que la máquina
 * necesite, cada uno con SU intervalo — cada X horas de horómetro,
 * cada X km y/o cada X días, LO QUE LLEGUE PRIMERO.
 *
 * El plan guarda la "línea base" del ÚLTIMO cambio realizado (fecha,
 * horómetro y km del momento). La alerta se calcula comparando esa
 * base contra el estado actual de la máquina: >= 90% del intervalo →
 * PRÓXIMO, >= 100% → VENCIDO.
 *
 * `ultimo_aviso_estado` evita campanitas repetidas (mismo patrón que
 * ultimo_aviso_dias en cobranza): guarda el nivel ya avisado
 * ('proximo'/'vencido') y se limpia al registrar el cambio.
 *
 * CHECK: al menos UNA frecuencia definida — un plan sin intervalo no
 * puede alertar nunca y es un error de captura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes_mantenimiento', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('maquina_id')
                ->constrained('maquinas')
                ->cascadeOnDelete();

            $table->string('nombre', 100);

            $table->decimal('frecuencia_horas', 10, 2)->nullable();
            $table->decimal('frecuencia_km', 12, 2)->nullable();
            $table->unsignedInteger('frecuencia_dias')->nullable();

            $table->date('fecha_ultimo_cambio');
            $table->decimal('horometro_ultimo_cambio', 12, 2)->nullable();
            $table->decimal('km_ultimo_cambio', 12, 2)->nullable();

            $table->string('ultimo_aviso_estado', 10)->nullable();

            $table->boolean('activo')->default(true);
            $table->string('notas', 255)->nullable();

            $table->timestamps();

            $table->index('maquina_id');
            $table->unique(['maquina_id', 'nombre']);
        });

        DB::statement(
            'ALTER TABLE planes_mantenimiento ADD CONSTRAINT planes_mantenimiento_frecuencia_definida
             CHECK (frecuencia_horas IS NOT NULL OR frecuencia_km IS NOT NULL OR frecuencia_dias IS NOT NULL)'
        );

        DB::statement(
            'ALTER TABLE planes_mantenimiento ADD CONSTRAINT planes_mantenimiento_frecuencias_positivas
             CHECK (
                 (frecuencia_horas IS NULL OR frecuencia_horas > 0)
                 AND (frecuencia_km IS NULL OR frecuencia_km > 0)
                 AND (frecuencia_dias IS NULL OR frecuencia_dias > 0)
             )'
        );

        DB::statement(
            "ALTER TABLE planes_mantenimiento ADD CONSTRAINT planes_mantenimiento_aviso_valido
             CHECK (ultimo_aviso_estado IS NULL OR ultimo_aviso_estado IN ('proximo', 'vencido'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('planes_mantenimiento');
    }
};
