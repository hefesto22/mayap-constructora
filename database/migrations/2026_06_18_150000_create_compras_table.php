<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `compras` — documento de compra a un proveedor.
 *
 * Al confirmarse (estado borrador → confirmada), por cada línea se registra
 * una entrada de inventario a la bodega destino vía RegistrarMovimientoService
 * (el costo capitaliza al promedio ponderado). Si es a crédito, queda lista
 * para generar la cuenta por pagar.
 *
 * AUTO-CÓDIGO: COM-{AÑO}-{NUMERO_5}, contador que se reinicia por año.
 *
 * COSTEO vs PAGO: el `costo_unitario` de cada línea es el costo NETO que
 * capitaliza a inventario. El ISV del documento (isv_cache) suma al total
 * que se le debe al proveedor (CxP) pero NO entra al costo de inventario.
 *
 * condicion_pago se snapshotea del proveedor al crear (puede diferir luego).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 25)->unique();

            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->restrictOnDelete();

            $table->foreignId('bodega_id')
                ->comment('Bodega destino donde entra el stock al confirmar.')
                ->constrained('bodegas')
                ->restrictOnDelete();

            $table->string('estado', 20)->default('borrador');
            $table->string('condicion_pago', 20)->default('contado');

            $table->date('fecha');
            $table->date('fecha_recepcion')
                ->nullable()
                ->comment('Fecha en que entró el stock (se setea al confirmar).');

            $table->string('numero_factura', 50)
                ->nullable()
                ->comment('Número de factura del proveedor.');

            $table->boolean('aplica_isv')->default(true);
            $table->decimal('isv_porcentaje', 5, 2)->default(15.00);

            $table->decimal('subtotal_cache', 14, 2)->default(0);
            $table->decimal('isv_cache', 14, 2)->default(0);
            $table->decimal('total_cache', 14, 2)->default(0);

            $table->text('notas')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
            $table->index('fecha');
            $table->index(['proveedor_id', 'estado']);
        });

        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_estado_valido
             CHECK (estado IN ('borrador', 'confirmada', 'anulada'))"
        );

        DB::statement(
            "ALTER TABLE compras ADD CONSTRAINT compras_condicion_pago_valida
             CHECK (condicion_pago IN ('contado', 'credito'))"
        );

        DB::statement(
            'ALTER TABLE compras ADD CONSTRAINT compras_isv_porcentaje_valido
             CHECK (isv_porcentaje >= 0 AND isv_porcentaje <= 100)'
        );

        DB::statement(
            'ALTER TABLE compras ADD CONSTRAINT compras_isv_consistente
             CHECK (aplica_isv = TRUE OR isv_porcentaje = 0)'
        );

        DB::statement(
            'ALTER TABLE compras ADD CONSTRAINT compras_totales_no_negativos
             CHECK (subtotal_cache >= 0 AND isv_cache >= 0 AND total_cache >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
