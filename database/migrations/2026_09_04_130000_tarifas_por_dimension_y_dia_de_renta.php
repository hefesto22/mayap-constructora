<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RATE CARD DE LA MÁQUINA + LA MODALIDAD MANDA EN CÓMO SE COBRA.
 * (Decidido con Mauricio, 2026-09-04.)
 *
 * ── 1. El costo del parte seguía siempre la tarifa por HORA ──────────────
 * Una volqueta hacía 12 viajes: el sistema guardaba los 12 viajes como dato
 * y le cargaba a la obra las HORAS por tarifa horaria. Como
 * CostoProyectoService suma esos costo_cache como costo de maquinaria, el
 * MARGEN de toda obra con volquetas o camiones salía mal. Era dinero, no
 * cosmética. La causa: la asignación pactaba una sola tarifa (por hora), así
 * que aunque el parte quisiera cobrar por viajes no tenía con qué.
 *
 * Ahora la asignación pacta la tarifa de SU dimensión y el parte cobra ahí:
 *   horas       → horas  × tarifa_hora_pactada
 *   kilometraje → km     × tarifa_km_pactada
 *   viajes      → viajes × tarifa_viaje_pactada
 *   flete       →          tarifa_flete_pactada (monto fijo por flete)
 *
 * ── 2. Presentaciones de renta: hora, día, semana y mes ──────────────────
 * Son las presentaciones que un cliente contrata, y sus precios NO son
 * múltiplos lineales: el benchmark de la industria pone la hora en ~15% del
 * día, el día en ~25% de la semana y la semana en ~28% del mes, porque hay
 * descuento por volumen. Derivar el día como `tarifa_hora × jornada` (8× la
 * hora) sobrecotizaba ~20%. Cada presentación lleva su propio precio.
 *
 * ── 3. `jornada_horas` cambia de significado ─────────────────────────────
 * Dejó de ser "la jornada de la máquina" — que no existe: hay días de 4
 * horas y días de 12 — y pasa a `horas_dia_renta`: cuántas horas INCLUYE el
 * día que se le vende al cliente. De ahí se derivan las horas de la semana
 * (× 6 días, lunes a sábado) y del mes (× 24 días), que es lo que permite
 * comparar lo pactado contra lo realmente trabajado y cobrar el excedente.
 * Del parte de obra propia se va: ahí no manda nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquinas', function (Blueprint $table): void {
            $table->decimal('tarifa_dia', 12, 2)->nullable()->after('tarifa_hora');
            $table->decimal('tarifa_semana', 12, 2)->nullable()->after('tarifa_dia');
            $table->decimal('tarifa_mes', 12, 2)->nullable()->after('tarifa_semana');
            $table->decimal('tarifa_flete', 12, 2)->nullable()->after('tarifa_km');
            $table->renameColumn('jornada_horas', 'horas_dia_renta');
        });

        DB::statement(
            'ALTER TABLE maquinas ADD CONSTRAINT maquinas_tarifas_no_negativas
             CHECK (
                 (tarifa_dia    IS NULL OR tarifa_dia    >= 0)
                 AND (tarifa_semana IS NULL OR tarifa_semana >= 0)
                 AND (tarifa_mes    IS NULL OR tarifa_mes    >= 0)
                 AND (tarifa_flete  IS NULL OR tarifa_flete  >= 0)
             )'
        );

        Schema::table('asignaciones_maquina', function (Blueprint $table): void {
            $table->decimal('tarifa_viaje_pactada', 12, 2)->nullable()->after('tarifa_hora_pactada');
            $table->decimal('tarifa_km_pactada', 12, 2)->nullable()->after('tarifa_viaje_pactada');
            $table->decimal('tarifa_flete_pactada', 12, 2)->nullable()->after('tarifa_km_pactada');
        });

        DB::statement(
            'ALTER TABLE asignaciones_maquina ADD CONSTRAINT asignaciones_tarifas_no_negativas
             CHECK (
                 (tarifa_viaje_pactada IS NULL OR tarifa_viaje_pactada >= 0)
                 AND (tarifa_km_pactada    IS NULL OR tarifa_km_pactada    >= 0)
                 AND (tarifa_flete_pactada IS NULL OR tarifa_flete_pactada >= 0)
             )'
        );

        // El parte ya no cobra siempre por hora, así que la columna deja de
        // mentir en su nombre: guarda la tarifa de la dimensión aplicada.
        Schema::table('partes_trabajo', function (Blueprint $table): void {
            $table->renameColumn('tarifa_hora_aplicada', 'tarifa_aplicada');
        });

        // Las líneas de renta admiten las dos presentaciones nuevas.
        DB::statement('ALTER TABLE proyecto_lineas_renta DROP CONSTRAINT IF EXISTS proyecto_lineas_renta_unidad_valida');
        DB::statement(
            "ALTER TABLE proyecto_lineas_renta ADD CONSTRAINT proyecto_lineas_renta_unidad_valida
             CHECK (unidad IN ('hora', 'dia', 'semana', 'mes', 'viaje', 'kilometro'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE proyecto_lineas_renta DROP CONSTRAINT IF EXISTS proyecto_lineas_renta_unidad_valida');
        DB::statement(
            "ALTER TABLE proyecto_lineas_renta ADD CONSTRAINT proyecto_lineas_renta_unidad_valida
             CHECK (unidad IN ('hora', 'dia', 'viaje', 'kilometro'))"
        );

        DB::statement('ALTER TABLE asignaciones_maquina DROP CONSTRAINT IF EXISTS asignaciones_tarifas_no_negativas');
        DB::statement('ALTER TABLE maquinas DROP CONSTRAINT IF EXISTS maquinas_tarifas_no_negativas');

        Schema::table('asignaciones_maquina', function (Blueprint $table): void {
            $table->dropColumn(['tarifa_viaje_pactada', 'tarifa_km_pactada', 'tarifa_flete_pactada']);
        });

        Schema::table('partes_trabajo', function (Blueprint $table): void {
            $table->renameColumn('tarifa_aplicada', 'tarifa_hora_aplicada');
        });

        Schema::table('maquinas', function (Blueprint $table): void {
            $table->renameColumn('horas_dia_renta', 'jornada_horas');
            $table->dropColumn(['tarifa_dia', 'tarifa_semana', 'tarifa_mes', 'tarifa_flete']);
        });
    }
};
