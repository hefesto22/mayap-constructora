<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmación de salida (decisión Mauricio 2026-07-15): la máquina no
 * puede "llegar" a dos obras a la vez — mientras tenga una llegada
 * confirmada SIN salida, ninguna otra obra puede confirmar su llegada.
 * El encargado marca "ya terminó aquí" (click en el evento ✅) y recién
 * entonces la siguiente obra puede confirmar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->timestamp('salida_confirmada_at')
                ->nullable()
                ->comment('Cuándo se confirmó que la máquina terminó/salió de la obra (null = sigue ahí o no ha llegado)');

            // Quién confirmó la salida. Sin ->comment(): la definición de
            // llave foránea no lo soporta (el fluent lo ignoraba).
            $table->foreignId('salida_confirmada_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('salida_confirmada_por');
            $table->dropColumn('salida_confirmada_at');
        });
    }
};
