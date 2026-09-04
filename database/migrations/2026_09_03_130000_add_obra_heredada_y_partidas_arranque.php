<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OBRA HEREDADA — el go-live con obras que ya venían caminando.
 *
 * Dos problemas que este cambio resuelve:
 *
 * 1. EL PRESUPUESTO. Hoy `subtotal_cache` se calcula sumando los renglones
 *    (ficha APU × cantidad). Reconstruir eso para una obra de hace ocho
 *    meses es imposible: nadie recuerda las 40 fichas y, peor, los precios
 *    que tenían entonces ya no existen en el catálogo. Lo que SÍ conocen con
 *    certeza es el monto que firmaron con el cliente. `monto_contratado`
 *    guarda ese número y, con `es_obra_heredada`, pasa a ser la base del
 *    cálculo en lugar de los renglones. Los renglones quedan opcionales:
 *    se pueden cargar después para control por capítulo sin mover el total.
 *
 * 2. EL GASTO PREVIO, EN PARTIDAS. El arrastre no se recuerda de una sola
 *    sentada: van apareciendo facturas ("faltó el hierro de mayo"). Un solo
 *    monto que se reescribe obliga a sumar de cabeza y no deja rastro de qué
 *    lo compone. Cada partida lleva fecha, rubro, monto y de qué era; los
 *    tres campos costo_arranque_* del proyecto pasan a ser CACHE de estos
 *    totales por rubro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->boolean('es_obra_heredada')->default(false)->after('avance_fisico_cache');
            $table->decimal('monto_contratado', 14, 2)->nullable()->after('es_obra_heredada');
        });

        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_monto_contratado_valido
             CHECK (monto_contratado IS NULL OR monto_contratado >= 0)'
        );

        Schema::create('costos_arranque_proyecto', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')
                ->constrained('proyectos')
                ->cascadeOnDelete();

            // materiales | mano_obra | maquinaria — espeja los tres rubros
            // de CostoProyectoService para que el desglose siga cuadrando.
            $table->string('rubro', 20);
            $table->decimal('monto', 14, 2);
            $table->date('fecha');
            $table->string('descripcion', 300);

            // Quién la cargó: en la carga inicial importa saber de quién es
            // la memoria detrás de cada cifra.
            $table->foreignId('registrado_por_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('proyecto_id');
            $table->index(['proyecto_id', 'rubro']);
        });

        DB::statement(
            "ALTER TABLE costos_arranque_proyecto ADD CONSTRAINT costos_arranque_rubro_valido
             CHECK (rubro IN ('materiales', 'mano_obra', 'maquinaria'))"
        );

        DB::statement(
            'ALTER TABLE costos_arranque_proyecto ADD CONSTRAINT costos_arranque_monto_positivo
             CHECK (monto > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('costos_arranque_proyecto');

        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_monto_contratado_valido');

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn(['es_obra_heredada', 'monto_contratado']);
        });
    }
};
