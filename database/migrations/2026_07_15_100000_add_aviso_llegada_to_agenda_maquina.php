<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aviso de llegada (decisión Mauricio 2026-07-15): campanita al encargado
 * cuando su máquina agendada llega dentro de la PRÓXIMA hora ("prepara el
 * acceso"). Esta marca hace el aviso idempotente: cada agendado avisa UNA
 * sola vez aunque el scheduler corra cada 10 minutos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->timestamp('aviso_llegada_at')
                ->nullable()
                ->comment('Cuándo se envió el aviso "tu máquina llega en 1 hora" (null = aún no)');
        });
    }

    public function down(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->dropColumn('aviso_llegada_at');
        });
    }
};
