<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `materiales` — el RECURSO FÍSICO canónico del inventario.
 *
 * Distinción de dominio clave (ADR-0003): un `material` es la cosa física
 * que se compra, almacena en bodega, se mueve y se consume (cemento, varilla,
 * agua). Es ÚNICO y GLOBAL — no se duplica por zona.
 *
 * Esto lo diferencia de `items` (la base de precios POR ZONA): un mismo
 * material físico ("CEMENTO GRIS 42.5kg") puede tener distinto PRECIO DE
 * VENTA en SRC, TGU o SPS — eso vive en `items`, uno por zona, y cada item
 * de categoría stockeable apunta a su material vía `items.material_id`.
 *
 * El COSTO de adquisición NO vive aquí: es por bodega y se pondera en
 * `existencias` (valor_total / cantidad) a partir de las compras. El mismo
 * material puede costar L.200 en una bodega y L.210 en otra.
 *
 * AUTO-CÓDIGO: {PREFIJO_CATEGORIA}-{NUMERO_5_DIGITOS}, GLOBAL (sin zona).
 * Ej: MAT-00001, HE-00042. Generado en `creating` con lockForUpdate.
 *
 * Solo las categorías físicas se inventarían (materiales, herramienta_equipo).
 * Mano de obra e indirectos NO son materiales — viven únicamente en `items`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materiales', function (Blueprint $table): void {
            $table->id();

            // No se elimina una unidad referenciada por materiales.
            $table->foreignId('unidad_medida_id')
                ->constrained('unidades_medida')
                ->restrictOnDelete();

            $table->string('categoria', 30)
                ->comment('Enum CategoriaItem físico: materiales, herramienta_equipo');

            $table->string('codigo', 30)
                ->comment('Código GLOBAL único del material físico. Ej: MAT-00001');

            $table->string('nombre', 200)
                ->comment('Nombre del recurso físico: "CEMENTO GRIS 42.5KG"');

            $table->text('descripcion')
                ->nullable()
                ->comment('Detalle largo: especificación, marca, presentación');

            $table->boolean('activo')
                ->default(true)
                ->comment('Marcar false en lugar de eliminar (preserva historial de inventario)');

            $table->timestamps();

            // Código GLOBAL único (a diferencia de items, que es por zona).
            $table->unique('codigo', 'materiales_codigo_unique');

            // Listado típico: "materiales de categoría X, activos".
            $table->index(['categoria', 'activo'], 'materiales_categoria_activo_idx');

            // Búsqueda por nombre.
            $table->index('nombre', 'materiales_nombre_idx');
        });

        // CHECK: solo categorías físicas inventariables.
        DB::statement(
            "ALTER TABLE materiales ADD CONSTRAINT materiales_categoria_valida
             CHECK (categoria IN ('materiales', 'herramienta_equipo'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('materiales');
    }
};
