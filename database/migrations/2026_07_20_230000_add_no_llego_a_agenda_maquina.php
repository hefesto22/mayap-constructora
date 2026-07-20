<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contingencia "NO llegó" (decisión Mauricio 2026-07-20): una agenda
 * cuya fecha pasó sin llegada confirmada queda ROJA en el calendario
 * hasta que alguien resuelva qué pasó. Si la máquina de verdad nunca
 * fue, se marca "no llegó" con motivo: el evento se retira y queda
 * constancia de quién lo marcó y cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->timestamp('no_llego_at')
                ->nullable()
                ->comment('Cuándo se marcó que la máquina NO llegó (null = no marcada)');

            // Quién dejó la constancia. Sin ->comment(): la definición de
            // llave foránea no lo soporta.
            $table->foreignId('no_llego_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('no_llego_motivo', 255)
                ->nullable()
                ->comment('Por qué no llegó — obligatorio al marcar la contingencia');
        });

        // La constancia siempre lleva motivo, y es EXCLUYENTE con la
        // llegada: una máquina no puede "haber llegado" y "no haber
        // llegado" a la vez.
        DB::statement(<<<'SQL'
            ALTER TABLE agenda_maquina
            ADD CONSTRAINT agenda_maquina_no_llego_consistente
            CHECK (
                no_llego_at IS NULL
                OR (no_llego_motivo IS NOT NULL AND llegada_confirmada_at IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agenda_maquina DROP CONSTRAINT IF EXISTS agenda_maquina_no_llego_consistente');

        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('no_llego_por');
            $table->dropColumn(['no_llego_at', 'no_llego_motivo']);
        });
    }
};
