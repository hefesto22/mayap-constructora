<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hora de ENTRADA prevista en la agenda de máquina: "llega el 15 a las
 * 7:00, 6 horas estimadas → fin estimado 13:00". La hora de fin no se
 * persiste — se deriva de entrada + horas previstas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->time('hora_entrada')->nullable()->after('fecha');
        });
    }

    public function down(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->dropColumn('hora_entrada');
        });
    }
};
