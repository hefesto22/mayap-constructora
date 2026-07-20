<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido del cliente (2026-07-20):
 *
 *  1. Cada empleado tiene SU frecuencia de pago (quincenal o mensual —
 *     semanal también se permite porque las planillas ya la manejan).
 *     Al armar una planilla, solo se ofrecen los empleados de esa
 *     frecuencia: nadie se cuela ni se olvida.
 *
 *  2. Tipo de pago HONORARIOS: profesionales con retención del 12.5%
 *     (ISR sobre honorarios, Honduras). El CHECK de tipo_pago se
 *     amplía para aceptarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table): void {
            $table->string('periodicidad_pago', 10)
                ->default('quincenal')
                ->after('tipo_pago');
        });

        DB::statement(
            "ALTER TABLE empleados ADD CONSTRAINT empleados_periodicidad_pago_valida
             CHECK (periodicidad_pago IN ('semanal', 'quincenal', 'mensual'))"
        );

        DB::statement('ALTER TABLE empleados DROP CONSTRAINT IF EXISTS empleados_tipo_pago_valido');
        DB::statement(
            "ALTER TABLE empleados ADD CONSTRAINT empleados_tipo_pago_valido
             CHECK (tipo_pago IN ('jornal', 'salario', 'destajo', 'honorarios'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE empleados DROP CONSTRAINT IF EXISTS empleados_periodicidad_pago_valida');
        DB::statement('ALTER TABLE empleados DROP CONSTRAINT IF EXISTS empleados_tipo_pago_valido');
        DB::statement(
            "ALTER TABLE empleados ADD CONSTRAINT empleados_tipo_pago_valido
             CHECK (tipo_pago IN ('jornal', 'salario', 'destajo'))"
        );

        Schema::table('empleados', function (Blueprint $table): void {
            $table->dropColumn('periodicidad_pago');
        });
    }
};
