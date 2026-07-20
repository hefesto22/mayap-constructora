<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad de trabajo por máquina (decisión Mauricio 2026-07-20):
 * pesada por horómetro, pick-ups por kilometraje, volquetas por viajes,
 * camiones por flete/actividad. Es el DEFAULT que sugiere el parte de
 * trabajo (cambiable por parte: una volqueta a veces va por horas).
 *
 * `tarifa_viaje` y `tarifa_km` completan el catálogo de tarifas para
 * cotizar rentas por viaje o por kilómetro (opcionales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquinas', function (Blueprint $table): void {
            $table->string('modalidad_trabajo', 20)->default('horas')->after('jornada_horas');
            $table->decimal('tarifa_viaje', 12, 2)->nullable()->after('modalidad_trabajo');
            $table->decimal('tarifa_km', 12, 2)->nullable()->after('tarifa_viaje');
        });

        DB::statement(
            "ALTER TABLE maquinas ADD CONSTRAINT maquinas_modalidad_trabajo_valida
             CHECK (modalidad_trabajo IN ('horas', 'kilometraje', 'viajes', 'flete'))"
        );

        DB::statement(
            'ALTER TABLE maquinas ADD CONSTRAINT maquinas_tarifas_extra_no_negativas
             CHECK ((tarifa_viaje IS NULL OR tarifa_viaje >= 0) AND (tarifa_km IS NULL OR tarifa_km >= 0))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE maquinas DROP CONSTRAINT IF EXISTS maquinas_modalidad_trabajo_valida');
        DB::statement('ALTER TABLE maquinas DROP CONSTRAINT IF EXISTS maquinas_tarifas_extra_no_negativas');

        Schema::table('maquinas', function (Blueprint $table): void {
            $table->dropColumn(['modalidad_trabajo', 'tarifa_viaje', 'tarifa_km']);
        });
    }
};
