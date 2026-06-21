<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza cada `item` (base de precios por zona) con su `material` físico.
 *
 * NULLABLE a propósito: solo los items de categorías stockeables
 * (materiales, herramienta_equipo) apuntan a un material. Los items de
 * mano de obra e indirectos NO son inventariables — quedan con material_id
 * nulo.
 *
 * Este es el PUENTE entre el libro de precios por zona y el inventario:
 * varios items (SRC, TGU, SPS) del mismo cemento apuntan al MISMO material.
 * El inventario, las compras y las requisiciones referencian el material;
 * las fichas/APU siguen referenciando el item (precio de su zona).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->foreignId('material_id')
                ->nullable()
                ->after('id')
                ->comment('Material físico al que corresponde este item de precio (null = no inventariable)')
                ->constrained('materiales')
                ->nullOnDelete();

            $table->index('material_id', 'items_material_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropForeign(['material_id']);
            $table->dropIndex('items_material_id_idx');
            $table->dropColumn('material_id');
        });
    }
};
