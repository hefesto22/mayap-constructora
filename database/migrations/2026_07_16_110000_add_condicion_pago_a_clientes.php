<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Condición de pago del cliente — espejo exacto de `proveedores`.
 *
 * - contado: la cuenta por cobrar vence el mismo día en que se emite.
 * - credito: vence a `dias_credito` días de la emisión.
 *
 * Alimenta la generación automática de cuentas por cobrar (rentas de
 * maquinaria hoy; anticipos de obras presupuestadas a futuro).
 * Default 'contado' — la condición más conservadora para los clientes
 * existentes: nadie gana crédito por accidente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('condicion_pago', 20)
                ->default('contado')
                ->after('ciudad');

            $table->unsignedSmallInteger('dias_credito')
                ->default(0)
                ->after('condicion_pago')
                ->comment('Días de crédito cuando la condición es crédito.');
        });

        DB::statement(
            "ALTER TABLE clientes ADD CONSTRAINT clientes_condicion_pago_valida
             CHECK (condicion_pago IN ('contado', 'credito'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clientes DROP CONSTRAINT IF EXISTS clientes_condicion_pago_valida');

        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn(['condicion_pago', 'dias_credito']);
        });
    }
};
