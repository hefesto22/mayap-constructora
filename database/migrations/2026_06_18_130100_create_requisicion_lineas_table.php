<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `requisicion_lineas` — un material pedido dentro de una requisición.
 *
 * Lleva las cuatro cantidades que permiten la trazabilidad eslabón por
 * eslabón (docs/arquitectura/sistema-completo.md §3):
 *  - cantidad_solicitada: lo que pidió la obra.
 *  - cantidad_autorizada: lo que aprobó Administración (puede ser menor).
 *  - cantidad_despachada: lo que Bodega efectivamente sacó.
 *  - cantidad_recibida:   lo que la obra confirmó que llegó.
 *
 * Si despachada ≠ recibida, la requisición cae en Discrepancia y se sabe
 * exactamente en qué material y por cuánto. Las nulables (autorizada) se
 * llenan al avanzar el estado; las default 0 arrancan en cero.
 *
 * Cantidades con 4 decimales (materiales fraccionarios: m³, kg, ml).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicion_lineas', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('requisicion_id')
                ->constrained('requisiciones')
                ->cascadeOnDelete();

            $table->foreignId('material_id')
                ->constrained('materiales')
                ->restrictOnDelete();

            $table->decimal('cantidad_solicitada', 16, 4);
            $table->decimal('cantidad_autorizada', 16, 4)->nullable();
            $table->decimal('cantidad_despachada', 16, 4)->default(0);
            $table->decimal('cantidad_recibida', 16, 4)->default(0);

            $table->timestamps();

            // Un material aparece una sola vez por requisición.
            $table->unique(['requisicion_id', 'material_id'], 'requisicion_lineas_req_material_unique');

            $table->index('material_id');
        });

        DB::statement(
            'ALTER TABLE requisicion_lineas ADD CONSTRAINT requisicion_lineas_cantidades_validas
             CHECK (
                cantidad_solicitada > 0
                AND (cantidad_autorizada IS NULL OR cantidad_autorizada >= 0)
                AND cantidad_despachada >= 0
                AND cantidad_recibida >= 0
             )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicion_lineas');
    }
};
