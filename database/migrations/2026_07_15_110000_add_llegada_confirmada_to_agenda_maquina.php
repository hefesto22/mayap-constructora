<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmación de llegada (decisión Mauricio 2026-07-15): el encargado,
 * desde el calendario, marca que la máquina YA llegó a su obra (click en
 * el evento azul → "Confirmar llegada"). Queda quién confirmó y a qué
 * hora, y el rol maquinaria recibe la campanita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->timestamp('llegada_confirmada_at')
                ->nullable()
                ->comment('Cuándo se confirmó que la máquina llegó a la obra (null = sin confirmar)');

            // Quién confirmó la llegada (normalmente el encargado de la
            // obra). Sin ->comment(): la definición de llave foránea no
            // lo soporta (el fluent lo ignoraba en silencio).
            $table->foreignId('llegada_confirmada_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('llegada_confirmada_por');
            $table->dropColumn('llegada_confirmada_at');
        });
    }
};
