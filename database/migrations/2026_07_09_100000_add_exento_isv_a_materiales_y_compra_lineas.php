<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISV por producto y por línea de compra (facturas mixtas SAR).
 *
 * `materiales.exento_isv` — marca FISCAL del producto (canasta básica,
 * exenciones de ley). Es propiedad del material físico, no del precio por
 * zona: se define una vez en el catálogo y el sistema la hereda solo.
 *
 * `compra_lineas.exento` — snapshot por línea (heredado del material al
 * capturar, editable por si la factura difiere). El ISV de la compra se
 * calcula SOLO sobre el valor efectivo de las líneas gravadas, igual que
 * una factura SAR separa Importe Exento / Importe Gravado 15%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materiales', function (Blueprint $table): void {
            $table->boolean('exento_isv')
                ->default(false)
                ->after('activo')
                ->comment('Producto exento de ISV por ley. Hereda a las líneas de compra.');
        });

        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->boolean('exento')
                ->default(false)
                ->after('costo_unitario')
                ->comment('Línea exenta de ISV (heredado del material, editable).');
        });
    }

    public function down(): void
    {
        Schema::table('compra_lineas', function (Blueprint $table): void {
            $table->dropColumn('exento');
        });

        Schema::table('materiales', function (Blueprint $table): void {
            $table->dropColumn('exento_isv');
        });
    }
};
