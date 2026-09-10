<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ¿A DÓNDE SE FUE LA MÁQUINA? (Mauricio, 2026-09-05).
 *
 * Con la estadía abierta, saber dónde está una máquina es fácil mientras
 * está EN la obra. Lo que faltaba era el otro lado: cuando el encargado
 * cierra la estadía, decir si volvió al patio, salió directo a otra obra o
 * se fue al taller. Sin esto, una máquina cerrada simplemente "desaparece"
 * del mapa hasta que alguien la vuelva a agendar.
 *
 * "Sigue en la obra" no es un valor de esta columna: es no cerrar la
 * estadía, que es el estado por defecto y no necesita confirmarse a diario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->string('destino_salida', 20)->nullable()->after('horometro_salida');
        });

        DB::statement(
            "ALTER TABLE agenda_maquina ADD CONSTRAINT agenda_destino_salida_valido
             CHECK (
                 destino_salida IS NULL
                 OR destino_salida IN ('bodega', 'otra_obra', 'taller')
             )"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agenda_maquina DROP CONSTRAINT IF EXISTS agenda_destino_salida_valido');

        Schema::table('agenda_maquina', function (Blueprint $table): void {
            $table->dropColumn('destino_salida');
        });
    }
};
