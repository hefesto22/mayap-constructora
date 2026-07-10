<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La compra mixta rompe el unique original (compra_id, material_id): el
 * mismo material SÍ puede aparecer dos veces en una factura cuando va a
 * destinos distintos (100 bolsas a bodega + 100 a obra).
 *
 * Regla nueva: un material no se repite AL MISMO DESTINO dentro de la
 * compra. COALESCE(...,0) hace que dos líneas heredando cabecera (ambos
 * destinos NULL) también cuenten como duplicado — sin él, Postgres trata
 * NULL ≠ NULL y permitiría repetidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE compra_lineas DROP CONSTRAINT compra_lineas_compra_material_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX compra_lineas_compra_material_destino_unique
            ON compra_lineas (compra_id, material_id, COALESCE(bodega_id, 0), COALESCE(proyecto_id, 0))
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS compra_lineas_compra_material_destino_unique');

        // Solo restaurable si no hay compras mixtas con material repetido.
        DB::statement(<<<'SQL'
            ALTER TABLE compra_lineas
            ADD CONSTRAINT compra_lineas_compra_material_unique
            UNIQUE (compra_id, material_id)
            SQL);
    }
};
