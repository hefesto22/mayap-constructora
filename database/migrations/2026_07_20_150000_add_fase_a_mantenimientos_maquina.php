<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fases del mantenimiento correctivo (decisión Mauricio 2026-07-20):
 * mientras el evento está en proceso, la reparación pasa por
 * diagnóstico → sin repuestos → compra de repuestos → reparación.
 *
 * `fecha_estimada_repuestos`: cuándo se estima que lleguen los
 * repuestos pedidos (aplica en las fases que esperan repuestos).
 *
 * `aviso_repuestos_at`: marca idempotente de "ya avisé que los
 * repuestos deberían haber llegado" — cambiar la fecha estimada la
 * reinicia (NULL) para rearmar la campanita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mantenimientos_maquina', function (Blueprint $table): void {
            $table->string('fase', 30)->default('diagnostico')->after('estado');
            $table->date('fecha_estimada_repuestos')->nullable()->after('fase');
            $table->timestamp('aviso_repuestos_at')->nullable()->after('fecha_estimada_repuestos');
        });

        DB::statement(
            "ALTER TABLE mantenimientos_maquina ADD CONSTRAINT mantenimientos_maquina_fase_valida
             CHECK (fase IN ('diagnostico', 'sin_repuestos', 'compra_repuestos', 'reparacion'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mantenimientos_maquina DROP CONSTRAINT IF EXISTS mantenimientos_maquina_fase_valida');

        Schema::table('mantenimientos_maquina', function (Blueprint $table): void {
            $table->dropColumn(['fase', 'fecha_estimada_repuestos', 'aviso_repuestos_at']);
        });
    }
};
