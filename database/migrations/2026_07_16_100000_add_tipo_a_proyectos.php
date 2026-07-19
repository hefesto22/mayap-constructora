<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de proyecto: presupuestado (obra con renglones APU, lo existente)
 * o renta_maquinaria (proyecto liviano: solo máquinas por horas/días).
 *
 * Default 'presupuestado' — TODOS los proyectos existentes son obras
 * presupuestadas, así que el backfill es implícito y sin riesgo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->string('tipo', 30)
                ->default('presupuestado')
                ->after('codigo');

            $table->index('tipo');
        });

        DB::statement(
            "ALTER TABLE proyectos ADD CONSTRAINT proyectos_tipo_valido
             CHECK (tipo IN ('presupuestado', 'renta_maquinaria'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_tipo_valido');

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropIndex(['tipo']);
            $table->dropColumn('tipo');
        });
    }
};
