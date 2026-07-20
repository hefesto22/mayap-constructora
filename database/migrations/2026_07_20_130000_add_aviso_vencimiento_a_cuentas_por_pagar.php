<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos escalonados de PAGOS a proveedores (decisión Mauricio
 * 2026-07-20): espejo exacto de la cobranza — campanita a gerencia y
 * recepción a los 7 días, 3 días, el DÍA del vencimiento y cuando la
 * cuenta ya venció impaga.
 *
 * `ultimo_aviso_dias` guarda el escalón MÁS CERCANO ya notificado:
 * 7 → 3 → 0 → -1 (vencida). Solo avanza, nunca retrocede — correr el
 * comando dos veces el mismo día no duplica campanitas. Cambiar la
 * fecha de vencimiento lo reinicia (NULL) para rearmar el ciclo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_por_pagar', function (Blueprint $table): void {
            $table->smallInteger('ultimo_aviso_dias')
                ->nullable()
                ->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_por_pagar', function (Blueprint $table): void {
            $table->dropColumn('ultimo_aviso_dias');
        });
    }
};
