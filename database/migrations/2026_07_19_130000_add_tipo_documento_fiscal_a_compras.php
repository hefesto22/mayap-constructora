<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documento fiscal emitido por el proveedor en cada compra (decisión
 * Mauricio 2026-07-19): factura / recibo por honorarios / boleta de
 * compra / ninguno.
 *
 * NULLABLE a propósito: el borrador puede capturarse antes de tener el
 * documento y las compras HISTÓRICAS quedan sin dato (no se inventa).
 * La exigencia vive en ConfirmarCompraService: sin tipo no se confirma,
 * y si es factura, sin número tampoco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            $table->string('tipo_documento_fiscal', 30)
                ->nullable()
                ->after('numero_factura');
        });

        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_tipo_documento_fiscal_valido
             CHECK (tipo_documento_fiscal IS NULL OR tipo_documento_fiscal IN
                 ('factura', 'recibo_honorarios', 'boleta_compra', 'ninguno'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_tipo_documento_fiscal_valido');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropColumn('tipo_documento_fiscal');
        });
    }
};
