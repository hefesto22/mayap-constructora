<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla de reportes fiscales ahora guarda DOS tipos por mes
 * (decisión Mauricio 2026-07-20): el de facturas de compras (existente)
 * y el nuevo de pagos a proveedores (abonos + comprobantes de
 * transferencia). El período deja de ser único por sí solo: la pareja
 * (tipo, periodo) es la que no se repite.
 *
 * Las filas existentes son todas de facturas (default del backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reportes_fiscales', function (Blueprint $table): void {
            $table->string('tipo', 20)->default('facturas')->after('id');

            $table->dropUnique(['periodo']);
            $table->unique(['tipo', 'periodo']);
        });

        DB::statement(
            "ALTER TABLE reportes_fiscales ADD CONSTRAINT reportes_fiscales_tipo_check CHECK (tipo IN ('facturas', 'pagos'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reportes_fiscales DROP CONSTRAINT IF EXISTS reportes_fiscales_tipo_check');

        Schema::table('reportes_fiscales', function (Blueprint $table): void {
            $table->dropUnique(['tipo', 'periodo']);
            $table->dropColumn('tipo');
            $table->unique(['periodo']);
        });
    }
};
