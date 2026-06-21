<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `consumos_combustible` — combustible cargado a una máquina mientras
 * trabaja en una obra (vía su asignación). El costo (litros × precio) se
 * carga a la obra, igual que las horas.
 *
 * El `precio_litro` y el `costo_cache` se congelan al registrar, así el costo
 * histórico no cambia si luego cambia el precio del combustible.
 *
 * Auto-código COMB-{AÑO}-##### en el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumos_combustible', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();

            $table->foreignId('asignacion_maquina_id')
                ->constrained('asignaciones_maquina')
                ->restrictOnDelete();

            $table->date('fecha');

            $table->decimal('cantidad_litros', 10, 2);
            $table->decimal('precio_litro', 12, 4);
            $table->decimal('costo_cache', 12, 2);

            $table->string('operador', 150)->nullable();
            $table->text('notas')->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('asignacion_maquina_id');
            $table->index('fecha');
            $table->index(['asignacion_maquina_id', 'fecha']);
        });

        DB::statement(
            'ALTER TABLE consumos_combustible ADD CONSTRAINT consumos_combustible_litros_positivos
             CHECK (cantidad_litros > 0)'
        );

        DB::statement(
            'ALTER TABLE consumos_combustible ADD CONSTRAINT consumos_combustible_precio_no_negativo
             CHECK (precio_litro >= 0)'
        );

        DB::statement(
            'ALTER TABLE consumos_combustible ADD CONSTRAINT consumos_combustible_costo_no_negativo
             CHECK (costo_cache >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('consumos_combustible');
    }
};
