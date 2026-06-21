<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `empleados` — personal de la constructora para la planilla.
 *
 * `tipo_pago` define cómo se calcula el monto en la planilla: jornal (días ×
 * tarifa), salario (tarifa fija del período) o destajo (se captura por tarea).
 * `tarifa_base` es el pago por día (jornal) o por período (salario); para
 * destajo queda en 0 (el monto es variable por tarea).
 *
 * Auto-código en el modelo (EMP-#####).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();
            $table->string('nombre', 255);
            $table->string('identidad', 20)->nullable()
                ->comment('Número de identidad / DNI. Opcional.');
            $table->string('cargo', 100)->nullable()
                ->comment('Puesto: maestro de obra, albañil, ayudante, etc.');

            $table->string('tipo_pago', 20)->default('jornal');
            $table->decimal('tarifa_base', 12, 2)->default(0)
                ->comment('Pago por día (jornal) o por período (salario). 0 en destajo.');

            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('nombre');
            $table->index('activo');
            $table->index('tipo_pago');
        });

        DB::statement(
            "ALTER TABLE empleados ADD CONSTRAINT empleados_tipo_pago_valido
             CHECK (tipo_pago IN ('jornal', 'salario', 'destajo'))"
        );

        DB::statement(
            'ALTER TABLE empleados ADD CONSTRAINT empleados_tarifa_no_negativa
             CHECK (tarifa_base >= 0)'
        );

        DB::statement(
            "ALTER TABLE empleados ADD CONSTRAINT empleados_identidad_formato
             CHECK (identidad IS NULL OR identidad ~ '^[0-9-]{8,20}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
