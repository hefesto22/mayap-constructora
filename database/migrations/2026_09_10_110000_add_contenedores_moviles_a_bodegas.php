<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BODEGAS MÓVILES / CONTENEDORES (Mauricio, 2026-09-10).
 *
 * "El agua de pipa es una pipa que siempre está llena en la bodega, hay
 * que marcar cuándo sale y cuándo regresa, si regresó llena o vacía para
 * mandar a llenarla otra vez... que funcione universal para cualquier
 * constructora."
 *
 * El problema no era el agua: el sistema solo conocía DOS clases de lugar
 * donde puede haber material —una bodega fija y una obra—. La pipa es un
 * tercero: un lugar que se mueve. Nombrarlo así lo vuelve universal sin
 * una línea de código por caso: la pipa de agua, la cisterna de diésel,
 * los cilindros de oxígeno, la camioneta de herramienta. Cada constructora
 * define los suyos.
 *
 * Y como es una bodega de verdad, TODO lo que ya existe sirve tal cual:
 * su existencia dice cuánta agua carga ahora mismo, salir a la obra es un
 * despacho normal valorado al promedio ponderado, y "¿cuánta agua salió?"
 * lo contesta el reporte de siempre. No hay nada nuevo que inventar.
 *
 *  - movil: esta bodega viaja.
 *  - capacidad: cuánto cabe lleno. Es lo que permite decir "regresó a la
 *    mitad" sin que nadie teclee un número.
 *  - maquina_id: qué vehículo la carga. El camión y el tanque son cosas
 *    distintas a propósito — el camión puede estar en el taller con el
 *    tanque lleno, y el tanque puede cambiar de camión.
 *  - material_id: qué carga. Con esto, el regreso no pregunta "¿de qué?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bodegas', function (Blueprint $table): void {
            $table->boolean('movil')->default(false)->after('activo');
            $table->decimal('capacidad', 14, 4)->nullable()->after('movil');
            $table->foreignId('maquina_id')->nullable()->after('capacidad')
                ->constrained('maquinas')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->after('maquina_id')
                ->constrained('materiales')->nullOnDelete();
        });

        // Una capacidad de cero o negativa no describe ningún recipiente.
        DB::statement(
            'ALTER TABLE bodegas ADD CONSTRAINT bodega_capacidad_positiva
             CHECK (capacidad IS NULL OR capacidad > 0)'
        );

        // Capacidad, vehículo y contenido solo tienen sentido si viaja.
        // Una bodega fija con "capacidad" sería un dato que nadie mantiene.
        DB::statement(
            'ALTER TABLE bodegas ADD CONSTRAINT bodega_fija_sin_datos_de_contenedor
             CHECK (movil = TRUE OR (capacidad IS NULL AND maquina_id IS NULL AND material_id IS NULL))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bodegas DROP CONSTRAINT IF EXISTS bodega_fija_sin_datos_de_contenedor');
        DB::statement('ALTER TABLE bodegas DROP CONSTRAINT IF EXISTS bodega_capacidad_positiva');

        Schema::table('bodegas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('material_id');
            $table->dropConstrainedForeignId('maquina_id');
            $table->dropColumn(['movil', 'capacidad']);
        });
    }
};
