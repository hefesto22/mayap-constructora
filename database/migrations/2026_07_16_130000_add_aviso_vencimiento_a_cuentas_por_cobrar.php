<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de aviso de cobranza en la cuenta por cobrar — el "ya avisé"
 * de los recordatorios escalonados (decisión Mauricio 2026-07-16:
 * avisar a 7 días, 3 días y el día del vencimiento).
 *
 * `ultimo_aviso_dias` guarda el escalón MÁS CERCANO ya notificado:
 *   7  → "vence en una semana"     (7 ≥ días restantes > 3)
 *   3  → "vence en pocos días"     (3 ≥ días restantes > 0)
 *   0  → "vence HOY"
 *  -1  → "ya venció" (una sola vez, al detectar el atraso)
 *
 * El escalón solo avanza (7 → 3 → 0 → -1), nunca se repite el mismo:
 * mismo patrón idempotente que aviso_llegada_at en la agenda.
 * NULL = nunca se ha avisado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_por_cobrar', function (Blueprint $table): void {
            $table->smallInteger('ultimo_aviso_dias')
                ->nullable()
                ->after('estado')
                ->comment('Escalón de aviso de vencimiento ya notificado: 7, 3, 0 o -1 (vencida). NULL = sin avisos.');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_por_cobrar', function (Blueprint $table): void {
            $table->dropColumn('ultimo_aviso_dias');
        });
    }
};
