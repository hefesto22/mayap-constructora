<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `ficha_lineas` — composición detallada de una ficha APU.
 *
 * Una ficha tiene N líneas. Cada línea es de uno de DOS TIPOS:
 *
 * 1) tipo='item' — referencia un item del catálogo de precios:
 *    - item_id NOT NULL
 *    - rendimiento (decimal 10,4) NOT NULL — el rendimiento BASE,
 *      sin desperdicio incluido
 *    - desperdicio_porcentaje (decimal 5,2) — la pérdida esperada en obra
 *    Cálculo:
 *      rendimiento_efectivo = rendimiento × (1 + desperdicio/100)
 *      subtotal             = rendimiento_efectivo × precio_actual_item
 *    La sección visual del reporte se deriva de la categoría del item.
 *
 * 2) tipo='porcentaje' — línea derivada (ej: "Herramienta menor 5% sobre MO"):
 *    - descripcion NOT NULL
 *    - porcentaje NOT NULL (qué % aplicar)
 *    - categoria_base NOT NULL (sobre qué subtotal: materiales, mano_obra,
 *      herramienta_equipo o costo_directo)
 *    - categoria_destino NOT NULL (en qué sección visual aparece la línea)
 *    Cálculo:
 *      base     = subtotal_categoria(categoria_base)
 *      subtotal = base × porcentaje/100
 *    Patrón clásico hondureño: HERRAMIENTA MENOR (3-5% sobre MO),
 *    IMPREVISTOS (3-5% sobre costo directo), SUPERVISIÓN (5-10% sobre MO).
 *
 * Los CHECK constraints en DB hacen que cada tipo SOLO pueda tener
 * pobladas las columnas que le corresponden — defensa en profundidad
 * incluso ante inserts directos.
 *
 * Orden topológico: las líneas de tipo `porcentaje` que dependen de
 * `costo_directo` deben calcularse DESPUÉS de las líneas que
 * dependen de una categoría puntual (porque costo_directo las suma
 * a todas). El Service implementa este orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ficha_lineas', function (Blueprint $table): void {
            $table->id();

            // Cuando se elimina la ficha, las líneas se van con ella.
            $table->foreignId('ficha_id')
                ->constrained('fichas')
                ->cascadeOnDelete();

            // Tipo discriminador — define qué columnas son requeridas.
            $table->string('tipo', 20)
                ->comment("Enum: 'item' | 'porcentaje'");

            $table->integer('orden')
                ->default(0)
                ->comment('Orden de despliegue dentro de su categoría destino');

            // ─── Columnas para tipo='item' ─────────────────────────
            // restrictOnDelete: si un item está usado en alguna ficha,
            // NO se puede eliminar el item (preserva integridad
            // histórica del catálogo). El usuario debe primero remover
            // la línea de la ficha o marcar el item como inactivo.
            $table->foreignId('item_id')
                ->nullable()
                ->constrained('items')
                ->restrictOnDelete();

            $table->decimal('rendimiento', 12, 6)
                ->nullable()
                ->comment('Rendimiento BASE (sin desperdicio). 6 decimales para casos como 1/9, 1/12 sin perder centavos. Solo para tipo=item.');

            $table->decimal('desperdicio_porcentaje', 5, 2)
                ->nullable()
                ->default(0)
                ->comment('% de pérdida esperada. Solo para tipo=item.');

            // ─── Columnas para tipo='porcentaje' ───────────────────
            $table->string('descripcion', 200)
                ->nullable()
                ->comment("Texto del rubro derivado: 'HERRAMIENTA MENOR', 'IMPREVISTOS'. Solo para tipo=porcentaje.");

            $table->decimal('porcentaje', 5, 2)
                ->nullable()
                ->comment('% a aplicar sobre la base. Solo para tipo=porcentaje.');

            $table->string('categoria_base', 30)
                ->nullable()
                ->comment("Sobre qué subtotal aplica: 'materiales', 'mano_obra', 'herramienta_equipo', 'costo_directo'. Solo para tipo=porcentaje.");

            $table->string('categoria_destino', 30)
                ->nullable()
                ->comment("En qué sección visual aparece: 'materiales', 'mano_obra', 'herramienta_equipo', 'indirectos'. Solo para tipo=porcentaje.");

            $table->text('notas')
                ->nullable()
                ->comment('Observaciones operativas opcionales');

            $table->timestamps();

            // Un mismo item NO se duplica en la misma ficha
            // (solo aplica a tipo=item, donde item_id NO es null —
            //  Postgres trata múltiples NULLs como distintos por defecto).
            $table->unique(['ficha_id', 'item_id'], 'ficha_lineas_ficha_item_unique');

            // Listado de líneas por ficha en orden
            $table->index(['ficha_id', 'orden'], 'ficha_lineas_ficha_orden_idx');

            // Búsqueda inversa: "qué fichas usan este item"
            // (clave para el indicador de cache desactualizado)
            $table->index('item_id', 'ficha_lineas_item_idx');
        });

        // ─── CHECK constraints: integridad por tipo ────────────────

        // Tipo válido
        DB::statement(
            "ALTER TABLE ficha_lineas ADD CONSTRAINT ficha_lineas_tipo_valido
             CHECK (tipo IN ('item', 'porcentaje'))"
        );

        // tipo='item' requiere item_id, rendimiento; prohíbe campos de %
        DB::statement(
            "ALTER TABLE ficha_lineas ADD CONSTRAINT ficha_lineas_item_completo
             CHECK (
                tipo <> 'item' OR (
                    item_id IS NOT NULL
                    AND rendimiento IS NOT NULL
                    AND descripcion IS NULL
                    AND porcentaje IS NULL
                    AND categoria_base IS NULL
                    AND categoria_destino IS NULL
                )
             )"
        );

        // tipo='porcentaje' requiere descripcion, porcentaje, categoria_base,
        // categoria_destino; prohíbe item_id, rendimiento.
        DB::statement(
            "ALTER TABLE ficha_lineas ADD CONSTRAINT ficha_lineas_porcentaje_completo
             CHECK (
                tipo <> 'porcentaje' OR (
                    descripcion IS NOT NULL
                    AND porcentaje IS NOT NULL
                    AND categoria_base IS NOT NULL
                    AND categoria_destino IS NOT NULL
                    AND item_id IS NULL
                    AND rendimiento IS NULL
                )
             )"
        );

        // Rangos numéricos
        DB::statement(
            'ALTER TABLE ficha_lineas ADD CONSTRAINT ficha_lineas_rendimiento_no_negativo
             CHECK (rendimiento IS NULL OR rendimiento >= 0)'
        );

        DB::statement(
            'ALTER TABLE ficha_lineas ADD CONSTRAINT ficha_lineas_desperdicio_rango
             CHECK (desperdicio_porcentaje IS NULL OR (desperdicio_porcentaje >= 0 AND desperdicio_porcentaje <= 100))'
        );

        DB::statement(
            'ALTER TABLE ficha_lineas ADD CONSTRAINT ficha_lineas_porcentaje_rango
             CHECK (porcentaje IS NULL OR (porcentaje >= 0 AND porcentaje <= 100))'
        );

        DB::statement(
            "ALTER TABLE ficha_lineas ADD CONSTRAINT ficha_lineas_categoria_base_valida
             CHECK (categoria_base IS NULL OR categoria_base IN ('materiales', 'mano_obra', 'herramienta_equipo', 'costo_directo'))"
        );

        DB::statement(
            "ALTER TABLE ficha_lineas ADD CONSTRAINT ficha_lineas_categoria_destino_valida
             CHECK (categoria_destino IS NULL OR categoria_destino IN ('materiales', 'mano_obra', 'herramienta_equipo', 'indirectos'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ficha_lineas');
    }
};
