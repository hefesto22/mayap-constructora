<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La agenda se simplifica (decisión Mauricio 2026-07-14): "la máquina
 * llega a las X a la obra Y el día Z" — sin horas estimadas, porque
 * nunca se sabe cuánto trabajará. Las horas REALES viven en el parte de
 * trabajo. PostgreSQL elimina el CHECK de horas junto con la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->dropColumn('horas_previstas');
        });
    }

    public function down(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            // Nullable en la vuelta atrás: los datos originales ya no existen.
            $table->decimal('horas_previstas', 5, 2)->nullable();
        });
    }
};
