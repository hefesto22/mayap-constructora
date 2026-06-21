<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `planillas` — corrida de pago de un período. Agrupa las líneas de pago
 * por empleado. Mientras está en borrador se edita; al cerrarse, sus líneas
 * cuentan en el costo de mano de obra de cada obra.
 *
 * Auto-código PLA-{AÑO}-##### en el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planillas', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();
            $table->string('periodicidad', 20)->default('semanal');

            $table->date('fecha_inicio');
            $table->date('fecha_fin');

            $table->string('estado', 20)->default('borrador');
            $table->decimal('total_cache', 14, 2)->default(0);

            $table->text('notas')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
            $table->index('fecha_inicio');
        });

        DB::statement(
            "ALTER TABLE planillas ADD CONSTRAINT planillas_periodicidad_valida
             CHECK (periodicidad IN ('semanal', 'quincenal', 'mensual'))"
        );

        DB::statement(
            "ALTER TABLE planillas ADD CONSTRAINT planillas_estado_valido
             CHECK (estado IN ('borrador', 'cerrada'))"
        );

        DB::statement(
            'ALTER TABLE planillas ADD CONSTRAINT planillas_fechas_coherentes
             CHECK (fecha_fin >= fecha_inicio)'
        );

        DB::statement(
            'ALTER TABLE planillas ADD CONSTRAINT planillas_total_no_negativo
             CHECK (total_cache >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('planillas');
    }
};
