<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materiales de CONSUMO INMEDIATO (agua de pipa, y en general consumibles
 * que no se almacenan): al confirmar su compra directa a obra, el sistema
 * genera automáticamente el movimiento de consumo por la misma cantidad.
 *
 * Resultado: el costo queda imputado a la obra (por la entrada de compra),
 * la trazabilidad completa vive en el libro de movimientos, y la existencia
 * neta es cero — sin "stock fantasma" de agua acumulándose en la obra.
 *
 * Regla asociada: estos materiales NO se compran a bodega (no se pueden
 * almacenar) — el Service lo rechaza al confirmar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materiales', function (Blueprint $table): void {
            $table->boolean('consumo_inmediato')
                ->default(false)
                ->after('exento_isv')
                ->comment('Se consume al recibirse en obra (agua de pipa). No almacenable en bodega.');
        });
    }

    public function down(): void
    {
        Schema::table('materiales', function (Blueprint $table): void {
            $table->dropColumn('consumo_inmediato');
        });
    }
};
