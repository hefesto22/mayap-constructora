<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MULTI-CATEGORIA (decision Mauricio 2026-07-20): hay facturas que traen
 * de VARIAS categorias a la vez (materiales + repuestos + oficina en un
 * solo documento). La cabecera pasa de UNA categoria a un CONJUNTO:
 *
 * - `categorias` (jsonb): array no vacio de valores validos — el CHECK
 *   con el operador de contencion (<@) garantiza que solo entren los
 *   cuatro valores del enum, y el indice GIN sirve el filtro del listado.
 * - Backfill: cada compra existente queda con su categoria de siempre,
 *   ahora como conjunto de un elemento.
 * - La columna `categoria` (y su CHECK e indice) se retira: una sola
 *   fuente de la verdad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            $table->jsonb('categorias')->nullable()->after('estado');
        });

        DB::statement('UPDATE compras SET categorias = jsonb_build_array(categoria)');
        DB::statement('ALTER TABLE compras ALTER COLUMN categorias SET NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE compras ALTER COLUMN categorias SET DEFAULT '["materiales"]'::jsonb
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE compras ADD CONSTRAINT compras_categorias_validas CHECK (
                jsonb_typeof(categorias) = 'array'
                AND jsonb_array_length(categorias) >= 1
                AND categorias <@ '["materiales", "taller", "equipo_construccion", "oficina"]'::jsonb
            )
        SQL);

        DB::statement('CREATE INDEX compras_categorias_gin ON compras USING gin (categorias)');

        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_categoria_valida');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropIndex(['categoria']);
            $table->dropColumn('categoria');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            $table->string('categoria', 30)->default('materiales')->after('estado');
        });

        // La principal manda al degradar: el primer elemento del conjunto.
        DB::statement('UPDATE compras SET categoria = categorias->>0');

        DB::statement(<<<'SQL'
            ALTER TABLE compras ADD CONSTRAINT compras_categoria_valida
            CHECK (categoria IN ('materiales', 'taller', 'equipo_construccion', 'oficina'))
        SQL);

        Schema::table('compras', function (Blueprint $table): void {
            $table->index('categoria');
        });

        DB::statement('DROP INDEX IF EXISTS compras_categorias_gin');
        DB::statement('ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_categorias_validas');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropColumn('categorias');
        });
    }
};
