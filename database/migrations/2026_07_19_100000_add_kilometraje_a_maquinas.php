<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kilometraje actual de la máquina — lectura MANUAL para las unidades
 * que se controlan por km (volquetas, camiones). A diferencia del
 * horómetro (que lo mueven los partes de trabajo), aquí nada lo
 * actualiza solo: se edita en la ficha de la máquina o se captura al
 * registrar un cambio de mantenimiento.
 *
 * Nullable a propósito: una excavadora sin odómetro simplemente no lo
 * llena y sus planes de mantenimiento van por horas o por tiempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquinas', function (Blueprint $table): void {
            $table->decimal('kilometraje_actual', 12, 2)
                ->nullable()
                ->after('horometro_actual');
        });
    }

    public function down(): void
    {
        Schema::table('maquinas', function (Blueprint $table): void {
            $table->dropColumn('kilometraje_actual');
        });
    }
};
