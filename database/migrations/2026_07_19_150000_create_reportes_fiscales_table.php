<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporte fiscal mensual (decisión Mauricio 2026-07-19): un PDF por mes
 * con TODAS las compras del período (anuladas marcadas) y las fotos de
 * sus facturas incrustadas — el archivo de control permanente.
 *
 * `fotos_incluidas` guarda las rutas EXACTAS que quedaron dentro del
 * PDF: la purga (7 días después) borra solo esas — una foto subida
 * después de generar el reporte jamás se pierde sin archivar.
 *
 * `fotos_purgadas_at` = ya se liberó el espacio de ese mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reportes_fiscales', function (Blueprint $table): void {
            $table->id();

            $table->date('periodo')->unique();
            $table->string('path');

            $table->unsignedInteger('compras_count')->default(0);
            $table->unsignedInteger('fotos_count')->default(0);
            $table->jsonb('fotos_incluidas')->nullable();

            $table->timestamp('fotos_purgadas_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes_fiscales');
    }
};
