<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `existencias` — stock de un MATERIAL físico en UNA ubicación.
 *
 * MULTI-UBICACIÓN (ADR-0002 §1): una ubicación es una bodega física O un
 * proyecto. Cada obra es una mini-bodega. Por eso la fila tiene
 * `bodega_id` nullable Y `proyecto_id` nullable, con un CHECK que exige
 * EXACTAMENTE UNO de los dos poblado. Se prefiere esto sobre una relación
 * polimórfica para conservar FKs reales con integridad referencial.
 *
 * COSTEO — PROMEDIO PONDERADO MÓVIL (ADR-0002 §3): la fila lleva
 * `cantidad` y `valor_total`. El costo promedio NO se almacena: se deriva
 * (`valor_total / cantidad`) para que nunca se desincronice. El promedio
 * se recalcula solo cuando entra mercadería; las salidas usan el promedio
 * vigente sin alterarlo.
 *
 * Hay como máximo UNA fila por (material, ubicación). El Service de
 * inventario hace firstOrCreate + lockForUpdate sobre esta fila en cada
 * movimiento, serializando escrituras concurrentes sobre el mismo stock.
 *
 * `cantidad` usa 4 decimales porque los materiales de construcción se
 * miden en fracciones (m³, kg, ml). `valor_total` usa 2 (HNL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('existencias', function (Blueprint $table): void {
            $table->id();

            // No se elimina un material con existencias registradas.
            $table->foreignId('material_id')
                ->constrained('materiales')
                ->restrictOnDelete();

            // Ubicación: bodega XOR proyecto (ver CHECK más abajo).
            $table->foreignId('bodega_id')
                ->nullable()
                ->constrained('bodegas')
                ->restrictOnDelete();

            $table->foreignId('proyecto_id')
                ->nullable()
                ->constrained('proyectos')
                ->restrictOnDelete();

            $table->decimal('cantidad', 16, 4)
                ->default(0)
                ->comment('Unidades en existencia en esta ubicación. NUNCA negativo.');

            $table->decimal('valor_total', 16, 2)
                ->default(0)
                ->comment('Valor total en HNL para WAC. costo_promedio = valor_total / cantidad');

            $table->timestamps();

            // Búsqueda de stock de un material en cualquier ubicación.
            $table->index('material_id');
            $table->index('bodega_id');
            $table->index('proyecto_id');
        });

        // CHECK: exactamente una ubicación poblada (bodega XOR proyecto).
        DB::statement(
            'ALTER TABLE existencias ADD CONSTRAINT existencias_una_ubicacion
             CHECK (
                (CASE WHEN bodega_id   IS NOT NULL THEN 1 ELSE 0 END) +
                (CASE WHEN proyecto_id IS NOT NULL THEN 1 ELSE 0 END) = 1
             )'
        );

        // CHECK: stock y valor nunca negativos.
        DB::statement(
            'ALTER TABLE existencias ADD CONSTRAINT existencias_no_negativas
             CHECK (cantidad >= 0 AND valor_total >= 0)'
        );

        // Unicidad de (material, ubicación). Índices únicos parciales porque
        // una de las dos columnas de ubicación siempre es NULL.
        DB::statement(
            'CREATE UNIQUE INDEX existencias_material_bodega_unique
             ON existencias (material_id, bodega_id)
             WHERE bodega_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX existencias_material_proyecto_unique
             ON existencias (material_id, proyecto_id)
             WHERE proyecto_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('existencias');
    }
};
