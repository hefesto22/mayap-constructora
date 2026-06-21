<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `planilla_lineas` — pago de un empleado en una planilla, cargado a una
 * obra (proyecto). El `monto_bruto` se calcula según el tipo de pago:
 *
 *   - jornal:  dias_trabajados × tarifa_aplicada
 *   - salario: tarifa_aplicada (fijo del período)
 *   - destajo: se captura directo (descripción de la tarea)
 *
 * `proyecto_id` puede ser NULL para personal no cargado a una obra (overhead).
 * Solo las líneas con obra cuentan en el costo de esa obra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planilla_lineas', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('planilla_id')
                ->constrained('planillas')
                ->cascadeOnDelete();

            $table->foreignId('empleado_id')
                ->constrained('empleados')
                ->restrictOnDelete();

            $table->foreignId('proyecto_id')->nullable()
                ->constrained('proyectos')
                ->restrictOnDelete();

            $table->string('tipo_pago', 20);

            $table->decimal('dias_trabajados', 5, 2)->nullable();
            $table->decimal('tarifa_aplicada', 12, 2)->default(0);
            $table->text('descripcion')->nullable();

            $table->decimal('monto_bruto', 12, 2)->default(0);

            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index('planilla_id');
            $table->index('empleado_id');
            $table->index('proyecto_id');
            $table->index(['proyecto_id', 'planilla_id']);

            // Un empleado aparece una sola vez por planilla.
            $table->unique(['planilla_id', 'empleado_id']);
        });

        DB::statement(
            "ALTER TABLE planilla_lineas ADD CONSTRAINT planilla_lineas_tipo_pago_valido
             CHECK (tipo_pago IN ('jornal', 'salario', 'destajo'))"
        );

        DB::statement(
            'ALTER TABLE planilla_lineas ADD CONSTRAINT planilla_lineas_montos_no_negativos
             CHECK (monto_bruto >= 0 AND tarifa_aplicada >= 0
                    AND (dias_trabajados IS NULL OR dias_trabajados >= 0))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('planilla_lineas');
    }
};
