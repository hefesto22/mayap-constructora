<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desperdicio % por defecto en el item de la base de precios.
 *
 * Se define UNA vez al crear/editar el item y se PRE-CARGA en cada línea de
 * ficha al elegir ese item, ahorrando tecleo y dejando el dato registrado
 * desde el origen.
 *
 * Es INFORMATIVO: no altera la fórmula del subtotal (rendimiento × precio),
 * igual que la hoja de Excel del cliente. Documenta el % de pérdida que ya
 * está considerado en el rendimiento efectivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->decimal('desperdicio_porcentaje', 5, 2)
                ->default(0)
                ->after('precio_unitario')
                ->comment('Desperdicio % por defecto; se pre-carga en las fichas. Informativo, no afecta el cálculo.');
        });

        DB::statement(
            'ALTER TABLE items ADD CONSTRAINT items_desperdicio_valido
             CHECK (desperdicio_porcentaje >= 0 AND desperdicio_porcentaje <= 100)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_desperdicio_valido');

        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('desperdicio_porcentaje');
        });
    }
};
