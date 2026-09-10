<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HORÓMETRO DE LLEGADA Y DE SALIDA (Mauricio, 2026-09-04).
 *
 * Cuando el encargado marca que la máquina llegó, registra con cuánto
 * horómetro llegó. Es el punto de partida real de la estadía: como la
 * permanencia ya no tiene fecha de fin, la única forma de saber cuánto
 * trabajó esa máquina EN ESA OBRA es comparar el horómetro con el que
 * entró contra el que tenía al salir.
 *
 * También sirve de amarre contra el parte diario: si el encargado reporta
 * horas que no cuadran con el recorrido del horómetro entre llegada y
 * salida, algo falta o algo sobra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->decimal('horometro_llegada', 10, 2)->nullable()->after('llegada_confirmada_por');
            $table->decimal('horometro_salida', 10, 2)->nullable()->after('salida_confirmada_por');
        });

        DB::statement(
            'ALTER TABLE agenda_maquina ADD CONSTRAINT agenda_horometros_coherentes
             CHECK (
                 (horometro_llegada IS NULL OR horometro_llegada >= 0)
                 AND (horometro_salida IS NULL OR horometro_salida >= 0)
                 AND (
                     horometro_llegada IS NULL
                     OR horometro_salida IS NULL
                     OR horometro_salida >= horometro_llegada
                 )
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agenda_maquina DROP CONSTRAINT IF EXISTS agenda_horometros_coherentes');

        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->dropColumn(['horometro_llegada', 'horometro_salida']);
        });
    }
};
