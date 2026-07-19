<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos de la factura del proveedor adjuntas a la compra (decisión
 * Mauricio 2026-07-19): array JSON de rutas en el disco public. Toda
 * imagen se convierte a WebP al subirla (ImageOptimizer de la casa)
 * para ahorrar espacio.
 *
 * CICLO DE VIDA: las fotos viven en el servidor solo hasta que el
 * reporte fiscal mensual las archiva en PDF — 7 días después de
 * generado el reporte, se purgan del disco y esta columna se limpia.
 * El PDF mensual queda como archivo permanente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            $table->jsonb('fotos_factura')
                ->nullable()
                ->after('tipo_documento_fiscal');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            $table->dropColumn('fotos_factura');
        });
    }
};
