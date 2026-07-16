<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prioridad de la solicitud: lo URGENTE ("sí o sí se necesita") le llega
 * al rol maquinaria marcado, para reorganizar el orden cuando la máquina
 * ya estaba comprometida en otra cosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_maquina', function (Blueprint $table): void {
            $table->string('prioridad', 20)->default('normal')->after('estado');
        });

        DB::statement("
            ALTER TABLE solicitudes_maquina
            ADD CONSTRAINT solicitudes_maquina_prioridad_check
            CHECK (prioridad IN ('normal', 'urgente'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE solicitudes_maquina DROP CONSTRAINT IF EXISTS solicitudes_maquina_prioridad_check');

        Schema::table('solicitudes_maquina', function (Blueprint $table): void {
            $table->dropColumn('prioridad');
        });
    }
};
