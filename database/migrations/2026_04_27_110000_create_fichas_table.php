<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `fichas` — Análisis de Precio Unitario (APU).
 *
 * Una ficha representa la "receta" de cómo se calcula el costo por
 * unidad de salida de un trabajo de obra civil. Ej: 1 M² de losa de
 * concreto aligerada tiene una ficha que detalla cuánto cemento,
 * arena, jornadas de albañil y horas de mezcladora se necesitan.
 *
 * Pertenece a una zona (la base de precios que usa) y tiene una
 * unidad de salida propia (M², ML, M³, GLB). Las líneas que la
 * componen viven en `ficha_lineas`.
 *
 * `parametros_tecnicos` (JSONB) guarda pares clave-valor descriptivos
 * que el ingeniero registra como contexto técnico de la ficha:
 * "VOLUMEN DE CONCRETO": 0.1, "ESPESOR": "10CM", "RESISTENCIA": "3000PSI".
 * Son INFORMATIVOS — el sistema NO los usa para cálculos. Salen al
 * PDF en la cabecera para que otro ingeniero entienda los supuestos.
 *
 * Cache de cálculo: `subtotal_cache` (= materiales + MO + HE + indirectos,
 * lo que el ingeniero llama "SUB TOTAL" en su Excel) y `precio_venta_cache`
 * se actualizan al guardar la ficha. La utilidad se aplica sobre el subtotal,
 * NO solo sobre los costos directos — si una ficha tiene indirectos,
 * estos también devengan utilidad. Si los precios de items cambian
 * externamente, el cache puede quedar stale; existe acción manual
 * "Recalcular fichas" + indicador visual cuando
 * `precio_calculado_at < max(items.precio_actualizado_at)`.
 *
 * AUTO-CÓDIGO: igual patrón que items — `{ZONA}-APU-#####` con 5
 * dígitos. APU es término reconocido en el oficio constructor
 * (Análisis de Precio Unitario). Generación con lockForUpdate
 * dentro de transacción → seguro bajo concurrencia.
 *
 * INMUTABILIDAD: zona_id NO se cambia después de crear (preserva
 * coherencia del código y de las líneas que referencian items de
 * esa zona). Filament aplica `disabledOn('edit')` al campo zona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fichas', function (Blueprint $table): void {
            $table->id();

            // FK con restrictOnDelete: no se elimina zona si tiene fichas.
            $table->foreignId('zona_id')
                ->constrained('zonas')
                ->restrictOnDelete();

            // FK con restrictOnDelete: la unidad de salida (M², ML, M³, GLB).
            $table->foreignId('unidad_medida_id')
                ->constrained('unidades_medida')
                ->restrictOnDelete();

            $table->string('codigo', 30)
                ->comment('Auto-generado: {ZONA}-APU-#####');

            $table->string('nombre', 300)
                ->comment('Nombre técnico largo: "LOSA DE CONCRETO ALIGERADA, E=10CM, 3000PSI VAR#4 @20CM"');

            $table->text('descripcion')
                ->nullable()
                ->comment('Detalle adicional / notas técnicas extendidas');

            $table->jsonb('parametros_tecnicos')
                ->nullable()
                ->comment('Pares clave-valor descriptivos: {"VOLUMEN_CONCRETO": "0.1", "ESPESOR": "10CM"}');

            $table->decimal('utilidad_porcentaje', 5, 2)
                ->default(25.00)
                ->comment('Porcentaje de utilidad sobre costo directo. Default 25%, editable por ficha.');

            $table->decimal('subtotal_cache', 12, 2)
                ->default(0)
                ->comment('Suma de las 4 categorías (incluye indirectos). Es el SUB TOTAL del Excel del oficio. Cache recalculado on-save.');

            $table->decimal('precio_venta_cache', 12, 2)
                ->default(0)
                ->comment('Subtotal + utilidad. Cache recalculado on-save.');

            $table->timestamp('precio_calculado_at')
                ->nullable()
                ->comment('Última vez que se recalculó el cache de precios');

            $table->boolean('activa')
                ->default(true)
                ->comment('Marcar inactiva en lugar de eliminar si fue referenciada en presupuestos');

            $table->timestamps();

            // Unicidad del código POR zona — no global
            $table->unique(['zona_id', 'codigo'], 'fichas_zona_codigo_unique');

            // Listado típico: "fichas activas de esta zona"
            $table->index(['zona_id', 'activa'], 'fichas_zona_activa_idx');

            // Búsqueda por nombre dentro de zona
            $table->index(['zona_id', 'nombre'], 'fichas_zona_nombre_idx');

            // Filtro "fichas con cache de precio desactualizado"
            $table->index('precio_calculado_at', 'fichas_precio_calculado_at_idx');
        });

        // GIN sobre parametros_tecnicos JSONB — sintaxis Postgres específica.
        // Laravel envuelve los argumentos de rawIndex en paréntesis y rompe
        // la sintaxis "USING GIN (...)", así que va con DB::statement directo.
        // Soporta búsquedas futuras tipo: WHERE parametros_tecnicos @> '{"ESPESOR":"10CM"}'.
        DB::statement('CREATE INDEX fichas_parametros_gin ON fichas USING GIN (parametros_tecnicos)');

        // CHECK constraints — defensa en profundidad a nivel DB.
        DB::statement(
            'ALTER TABLE fichas ADD CONSTRAINT fichas_utilidad_no_negativa
             CHECK (utilidad_porcentaje >= 0)'
        );

        DB::statement(
            'ALTER TABLE fichas ADD CONSTRAINT fichas_subtotal_no_negativo
             CHECK (subtotal_cache >= 0)'
        );

        DB::statement(
            'ALTER TABLE fichas ADD CONSTRAINT fichas_precio_venta_no_negativo
             CHECK (precio_venta_cache >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fichas');
    }
};
