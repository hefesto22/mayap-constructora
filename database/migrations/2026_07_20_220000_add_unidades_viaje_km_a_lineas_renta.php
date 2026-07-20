<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renta por viaje y por kilómetro (decisión Mauricio 2026-07-20): las
 * volquetas se cotizan por viajes (origen → destino) y los pick-ups
 * pueden cotizarse por km — además de las horas/días originales. El
 * CHECK de unidad se amplía; la tarifa sugerida sale del catálogo de
 * la máquina (tarifa_viaje / tarifa_km).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE proyecto_lineas_renta DROP CONSTRAINT IF EXISTS proyecto_lineas_renta_unidad_valida');
        DB::statement(
            "ALTER TABLE proyecto_lineas_renta ADD CONSTRAINT proyecto_lineas_renta_unidad_valida
             CHECK (unidad IN ('hora', 'dia', 'viaje', 'kilometro'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE proyecto_lineas_renta DROP CONSTRAINT IF EXISTS proyecto_lineas_renta_unidad_valida');
        DB::statement(
            "ALTER TABLE proyecto_lineas_renta ADD CONSTRAINT proyecto_lineas_renta_unidad_valida
             CHECK (unidad IN ('hora', 'dia'))"
        );
    }
};
