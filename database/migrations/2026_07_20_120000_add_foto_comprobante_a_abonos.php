<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto del comprobante de transferencia por abono (decisión Mauricio
 * 2026-07-20): UNA foto por abono — si hubo dos depósitos, se registran
 * dos abonos, cada uno con su comprobante.
 *
 * La foto es TEMPORAL en el servidor (WebP en el disco public): el
 * reporte mensual de pagos la archiva en PDF y la purga la libera
 * 7 días después, igual que las fotos de facturas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonos', function (Blueprint $table): void {
            $table->string('foto_comprobante')->nullable()->after('referencia');
        });
    }

    public function down(): void
    {
        Schema::table('abonos', function (Blueprint $table): void {
            $table->dropColumn('foto_comprobante');
        });
    }
};
