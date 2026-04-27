<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `items` — base de precios POR ZONA.
 *
 * Cada zona tiene su propio listado de items con precios independientes.
 * Un mismo código (ej: "CEM-ARG-50") puede existir en varias zonas con
 * precios distintos — la unicidad es (zona_id, codigo), NO global.
 *
 * Categoría es enum tipado a nivel PHP; en DB se persiste como string
 * con CHECK constraint para preservar integridad incluso si se inserta
 * directamente vía SQL (importaciones futuras, scripts de mantenimiento).
 *
 * `precio_actualizado_at` lo gestiona ItemObserver: se setea cuando
 * cambia `precio_unitario`, NO cuando cambian otros campos. Permite
 * filtrar "items con precios viejos" sin que un edit de descripción
 * resetee el contador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->id();

            // FK con restrictOnDelete: no se elimina zona si tiene items asociados.
            $table->foreignId('zona_id')
                ->constrained('zonas')
                ->restrictOnDelete();

            // FK con restrictOnDelete: no se elimina unidad si está referenciada en items.
            $table->foreignId('unidad_medida_id')
                ->constrained('unidades_medida')
                ->restrictOnDelete();

            $table->string('categoria', 30)
                ->comment('Enum: materiales, mano_obra, herramienta_equipo, indirectos');

            $table->string('codigo', 30)
                ->comment('Código corto del maestro de obras: libre, único POR zona');

            $table->string('nombre', 200)
                ->comment('Descripción operativa: "Cemento gris saco 50kg Argos"');

            $table->text('descripcion')
                ->nullable()
                ->comment('Detalle largo del item (qué es, cómo se usa)');

            $table->decimal('precio_unitario', 12, 2)
                ->default(0)
                ->comment('Precio en HNL. NUNCA float — siempre decimal');

            $table->text('observaciones_precio')
                ->nullable()
                ->comment('Contexto del precio: "incluye flete", "descuento por volumen"');

            $table->timestamp('precio_actualizado_at')
                ->nullable()
                ->comment('Última vez que cambió precio_unitario (gestionado por ItemObserver)');

            $table->boolean('activo')
                ->default(true)
                ->comment('Marcar como false en lugar de eliminar (preserva snapshots de presupuestos)');

            $table->timestamps();

            // Unicidad de código POR zona — no global
            $table->unique(['zona_id', 'codigo'], 'items_zona_codigo_unique');

            // Listado típico: "items de zona X, categoría Y, activos"
            $table->index(
                ['zona_id', 'categoria', 'activo'],
                'items_zona_categoria_activo_idx'
            );

            // Búsqueda por nombre dentro de zona
            $table->index(['zona_id', 'nombre'], 'items_zona_nombre_idx');

            // Filtro "precios desactualizados"
            $table->index('precio_actualizado_at', 'items_precio_actualizado_at_idx');
        });

        // CHECK constraints a nivel DB para defensa en profundidad —
        // protege incluso ante inserts directos por SQL/scripts.
        DB::statement(
            'ALTER TABLE items ADD CONSTRAINT items_precio_unitario_no_negativo
             CHECK (precio_unitario >= 0)'
        );

        DB::statement(
            "ALTER TABLE items ADD CONSTRAINT items_categoria_valida
             CHECK (categoria IN ('materiales', 'mano_obra', 'herramienta_equipo', 'indirectos'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
