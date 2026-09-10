<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EL TOTAL QUE DICE LA FACTURA (Mauricio, 2026-09-05).
 *
 * "En compras registrar debería ser sencillo... el hecho de que cada
 * factura se tenga que agregar y seleccionar cosas lo hace muy complejo,
 * más cuando son 50 compras en un solo día."
 *
 * Con la captura diferida, quien registra ya no escribe las líneas: pone
 * el TOTAL de la factura y sube la foto. Las líneas las captura después
 * el bodeguero, que tiene la mercadería enfrente.
 *
 * `total_factura` es ese número declarado, y es contra él que se cuadra
 * lo capturado. `total_cache` sigue siendo lo DERIVADO de las líneas: si
 * los dos no coinciden, la compra está descuadrada y no se completa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            $table->decimal('total_factura', 14, 2)->nullable()->after('total_cache');
        });

        DB::statement(
            'ALTER TABLE compras ADD CONSTRAINT compras_total_factura_valido
             CHECK (total_factura IS NULL OR total_factura >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_total_factura_valido');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropColumn('total_factura');
        });
    }
};
