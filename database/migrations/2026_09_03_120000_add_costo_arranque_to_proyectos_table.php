<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COSTO DE ARRANQUE — obras que ya venían caminando antes del sistema.
 *
 * Al arrancar MAYAP en producción hay obras a medio construir: llevan meses
 * gastando material, planilla y maquinaria FUERA del sistema. Como
 * CostoProyectoService solo suma lo que está registrado (movimientos de
 * inventario, partes de trabajo + combustible, planillas cerradas), esas
 * obras entrarían con costo real 0 → margen 100% y presupuesto consumido 0%.
 * Los KPIs mentirían desde el día uno, y al cargar el primer gasto del mes
 * siguiente el margen se desplomaría como si la obra se hubiera descontrolado.
 *
 * Este saldo es el arrastre histórico, capturado por rubro para que el
 * reporte de costos siga cuadrando materiales / mano de obra / maquinaria.
 * Es un dato de carga inicial, NO una puerta para inventar costos: se
 * registra con permiso propio, solo mientras la obra no esté finalizada o
 * cancelada, y cada cambio queda en la bitácora del proyecto.
 *
 * CHECK: los tres rubros >= 0 (un arrastre negativo no existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->decimal('costo_arranque_materiales', 14, 2)->default(0)->after('avance_fisico_cache');
            $table->decimal('costo_arranque_mano_obra', 14, 2)->default(0)->after('costo_arranque_materiales');
            $table->decimal('costo_arranque_maquinaria', 14, 2)->default(0)->after('costo_arranque_mano_obra');
            $table->text('costo_arranque_nota')->nullable()->after('costo_arranque_maquinaria');
            $table->timestamp('costo_arranque_registrado_at')->nullable()->after('costo_arranque_nota');
        });

        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_costo_arranque_no_negativo
             CHECK (costo_arranque_materiales >= 0
                AND costo_arranque_mano_obra >= 0
                AND costo_arranque_maquinaria >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_costo_arranque_no_negativo');

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn([
                'costo_arranque_materiales',
                'costo_arranque_mano_obra',
                'costo_arranque_maquinaria',
                'costo_arranque_nota',
                'costo_arranque_registrado_at',
            ]);
        });
    }
};
