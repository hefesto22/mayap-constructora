<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones y neto en las líneas de planilla — lo que el recibo de
 * pago formal necesita mostrar:
 *
 *   bruto − retención (% ISR honorarios, 12.5 sugerido) − deducciones
 *   (adelantos, etc.) = NETO a pagar.
 *
 * El costo de mano de obra de las obras sigue siendo el BRUTO (lo que
 * cuesta el trabajo); la retención es plata que se aparta, no un menor
 * costo. Las líneas existentes quedan con neto = bruto (backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planilla_lineas', function (Blueprint $table): void {
            $table->decimal('retencion_porcentaje', 5, 2)->nullable()->after('monto_bruto');
            $table->decimal('retencion_monto', 14, 2)->default(0)->after('retencion_porcentaje');
            $table->decimal('deducciones', 14, 2)->default(0)->after('retencion_monto');
            $table->decimal('monto_neto', 14, 2)->default(0)->after('deducciones');
        });

        DB::statement(
            'ALTER TABLE planilla_lineas ADD CONSTRAINT planilla_lineas_retencion_pct_valida
             CHECK (retencion_porcentaje IS NULL OR (retencion_porcentaje >= 0 AND retencion_porcentaje <= 100))'
        );

        DB::statement(
            'ALTER TABLE planilla_lineas ADD CONSTRAINT planilla_lineas_retenciones_no_negativas
             CHECK (retencion_monto >= 0 AND deducciones >= 0 AND monto_neto >= 0)'
        );

        DB::statement('ALTER TABLE planilla_lineas DROP CONSTRAINT IF EXISTS planilla_lineas_tipo_pago_valido');
        DB::statement(
            "ALTER TABLE planilla_lineas ADD CONSTRAINT planilla_lineas_tipo_pago_valido
             CHECK (tipo_pago IN ('jornal', 'salario', 'destajo', 'honorarios'))"
        );

        // Backfill: lo ya pagado no tenía retenciones — neto = bruto.
        DB::statement('UPDATE planilla_lineas SET monto_neto = monto_bruto');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE planilla_lineas DROP CONSTRAINT IF EXISTS planilla_lineas_retencion_pct_valida');
        DB::statement('ALTER TABLE planilla_lineas DROP CONSTRAINT IF EXISTS planilla_lineas_retenciones_no_negativas');
        DB::statement('ALTER TABLE planilla_lineas DROP CONSTRAINT IF EXISTS planilla_lineas_tipo_pago_valido');
        DB::statement(
            "ALTER TABLE planilla_lineas ADD CONSTRAINT planilla_lineas_tipo_pago_valido
             CHECK (tipo_pago IN ('jornal', 'salario', 'destajo'))"
        );

        Schema::table('planilla_lineas', function (Blueprint $table): void {
            $table->dropColumn(['retencion_porcentaje', 'retencion_monto', 'deducciones', 'monto_neto']);
        });
    }
};
