<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `compra_lineas` — un item comprado dentro de una compra.
 *
 * `costo_unitario` es el costo NETO que capitaliza a inventario (alimenta el
 * promedio ponderado al confirmar). `subtotal` = cantidad × costo_unitario.
 *
 * Cantidad con 4 decimales (materiales fraccionarios); montos con 2 (HNL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_lineas', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('compra_id')
                ->constrained('compras')
                ->cascadeOnDelete();

            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            $table->decimal('cantidad', 16, 4);
            $table->decimal('costo_unitario', 16, 4);
            $table->decimal('subtotal', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['compra_id', 'item_id'], 'compra_lineas_compra_item_unique');
            $table->index('item_id');
        });

        DB::statement(
            'ALTER TABLE compra_lineas ADD CONSTRAINT compra_lineas_montos_validos
             CHECK (cantidad > 0 AND costo_unitario >= 0 AND subtotal >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_lineas');
    }
};
