<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partes de trabajo multi-modalidad (decisión Mauricio 2026-07-20):
 * además de las horas del día (que siguen siendo obligatorias — el
 * costo interno es horas × tarifa), el parte registra el dato con el
 * que la máquina TRABAJA/COBRA según su modalidad:
 *
 * - kilometraje: `km_recorridos` (suma al kilometraje de la máquina y
 *   alimenta su mantenimiento preventivo por km).
 * - viajes: `viajes` + `viaje_origen` → `viaje_destino` + material.
 * - flete: `actividad` (texto libre del motivo, sin catálogo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partes_trabajo', function (Blueprint $table): void {
            $table->string('modalidad', 20)->default('horas')->after('metodo_captura');

            $table->decimal('km_recorridos', 10, 2)->nullable()->after('motivo_horas_extra');

            $table->unsignedSmallInteger('viajes')->nullable()->after('km_recorridos');
            $table->string('viaje_origen', 150)->nullable()->after('viajes');
            $table->string('viaje_destino', 150)->nullable()->after('viaje_origen');
            $table->string('viaje_material', 150)->nullable()->after('viaje_destino');

            $table->string('actividad')->nullable()->after('viaje_material');
        });

        DB::statement(
            "ALTER TABLE partes_trabajo ADD CONSTRAINT partes_trabajo_modalidad_valida
             CHECK (modalidad IN ('horas', 'kilometraje', 'viajes', 'flete'))"
        );

        // Cada modalidad exige SU dato: sin km no hay parte de
        // kilometraje, sin nº de viajes no hay parte de viajes, sin
        // actividad no hay flete.
        DB::statement(
            "ALTER TABLE partes_trabajo ADD CONSTRAINT partes_trabajo_dato_de_modalidad
             CHECK (
                 (modalidad <> 'kilometraje' OR km_recorridos > 0)
                 AND (modalidad <> 'viajes' OR viajes > 0)
                 AND (modalidad <> 'flete' OR actividad IS NOT NULL)
             )"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE partes_trabajo DROP CONSTRAINT IF EXISTS partes_trabajo_modalidad_valida');
        DB::statement('ALTER TABLE partes_trabajo DROP CONSTRAINT IF EXISTS partes_trabajo_dato_de_modalidad');

        Schema::table('partes_trabajo', function (Blueprint $table): void {
            $table->dropColumn(['modalidad', 'km_recorridos', 'viajes', 'viaje_origen', 'viaje_destino', 'viaje_material', 'actividad']);
        });
    }
};
