<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REPARACIÓN EN SITIO (Mauricio, 2026-09-05).
 *
 * "No tiene lógica mandarla al taller si se puede solucionar en el mismo
 * lugar." Buena parte de las averías de obra se arreglan ahí mismo con un
 * repuesto o una herramienta que alguien tiene que llevar. El sistema
 * mandaba TODA avería al taller y cortaba la asignación, lo que obligaba
 * a inventar una salida que no ocurrió y hacía perder la máquina a la obra.
 *
 *  - en_sitio: la reparación es EN LA OBRA; la máquina no se mueve.
 *  - necesita: qué hay que llevarle para repararla (repuesto, herramienta,
 *    mecánico). Es el pedido que recepción y maquinaria despachan.
 *  - proyecto_id: A DÓNDE llevarlo. Sin esto el pedido es un papel sin
 *    dirección.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mantenimientos_maquina', function (Blueprint $table): void {
            $table->boolean('en_sitio')->default(false)->after('estado');
            $table->text('necesita')->nullable()->after('en_sitio');
            $table->foreignId('proyecto_id')->nullable()->after('maquina_id')
                ->constrained('proyectos')->nullOnDelete();
        });

        // Una reparación EN SITIO sin obra no se puede despachar: hay que
        // saber a dónde llevar el repuesto.
        DB::statement(
            'ALTER TABLE mantenimientos_maquina ADD CONSTRAINT mantenimiento_en_sitio_con_obra
             CHECK (en_sitio = false OR proyecto_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mantenimientos_maquina DROP CONSTRAINT IF EXISTS mantenimiento_en_sitio_con_obra');

        Schema::table('mantenimientos_maquina', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proyecto_id');
            $table->dropColumn(['en_sitio', 'necesita']);
        });
    }
};
