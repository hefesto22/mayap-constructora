<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `maquinas` — catálogo de maquinaria pesada de la constructora.
 *
 * El `horometro_actual` es el saldo del reloj de horas de la máquina; lo
 * mueven los partes de trabajo (nunca a mano), igual que las existencias se
 * mueven con movimientos. La `tarifa_hora` es el costo por defecto; al
 * asignar la máquina a una obra se podrá pactar otra tarifa para ese trabajo.
 *
 * Auto-código en el modelo (MAQ-#####). Montos en NUMERIC, nunca float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maquinas', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();
            $table->string('nombre', 255);
            $table->string('tipo', 30)->default('otro');

            $table->string('marca', 100)->nullable();
            $table->string('modelo', 100)->nullable();
            $table->unsignedSmallInteger('anio')->nullable();
            $table->string('serie', 100)->nullable()
                ->comment('Número de serie / placa / identificador físico.');

            // Saldo del horómetro (horas acumuladas). Lo mueven los partes.
            $table->decimal('horometro_actual', 10, 2)->default(0);

            // Tarifa por defecto; la asignación a obra puede sobreescribirla.
            $table->decimal('tarifa_hora', 12, 2)->default(0);

            // Jornada estándar para determinar horas extra (requieren motivo).
            $table->decimal('jornada_horas', 5, 2)->default(8);

            $table->string('estado', 20)->default('disponible');

            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('nombre');
            $table->index('activo');
            $table->index('estado');
            $table->index('tipo');
        });

        DB::statement(
            "ALTER TABLE maquinas ADD CONSTRAINT maquinas_tipo_valido
             CHECK (tipo IN ('excavadora','retroexcavadora','cargadora','volqueta','motoniveladora','compactadora','bulldozer','grua','otro'))"
        );

        DB::statement(
            "ALTER TABLE maquinas ADD CONSTRAINT maquinas_estado_valido
             CHECK (estado IN ('disponible','asignada','mantenimiento','baja'))"
        );

        DB::statement(
            'ALTER TABLE maquinas ADD CONSTRAINT maquinas_horometro_no_negativo
             CHECK (horometro_actual >= 0)'
        );

        DB::statement(
            'ALTER TABLE maquinas ADD CONSTRAINT maquinas_tarifa_no_negativa
             CHECK (tarifa_hora >= 0)'
        );

        DB::statement(
            'ALTER TABLE maquinas ADD CONSTRAINT maquinas_jornada_positiva
             CHECK (jornada_horas > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('maquinas');
    }
};
