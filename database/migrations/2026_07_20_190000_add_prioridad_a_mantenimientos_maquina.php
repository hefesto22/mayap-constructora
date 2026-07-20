<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prioridad de reparación (decisión Mauricio 2026-07-20): gerencia o
 * recepción marcan qué máquina en mantenimiento es la MÁS importante
 * (urgente / alta / normal). El taller sabe cuál atacar primero y el
 * cambio avisa por campanita y queda en la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mantenimientos_maquina', function (Blueprint $table): void {
            $table->string('prioridad', 20)->default('normal')->after('estado');

            $table->index(['estado', 'prioridad']);
        });

        DB::statement(
            "ALTER TABLE mantenimientos_maquina ADD CONSTRAINT mantenimientos_maquina_prioridad_valida
             CHECK (prioridad IN ('urgente', 'alta', 'normal'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mantenimientos_maquina DROP CONSTRAINT IF EXISTS mantenimientos_maquina_prioridad_valida');

        Schema::table('mantenimientos_maquina', function (Blueprint $table): void {
            $table->dropIndex(['estado', 'prioridad']);
            $table->dropColumn('prioridad');
        });
    }
};
