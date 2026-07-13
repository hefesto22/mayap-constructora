<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda de máquina — compromiso FUTURO por día y horas ("el 15 va 4h a
 * Las Palmas"). Complementa a los partes de trabajo (horas REALES ya
 * trabajadas): agenda = plan, parte = realidad. El calendario pinta la
 * agenda en azul y los partes en verde; el hueco = máquina libre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda_maquina', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('maquina_id')
                ->constrained('maquinas')
                ->restrictOnDelete();

            $table->foreignId('proyecto_id')
                ->constrained('proyectos')
                ->restrictOnDelete();

            $table->date('fecha');

            $table->decimal('horas_previstas', 5, 2);

            $table->string('notas', 255)->nullable();

            // Quién agendó (auditoría ligera).
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Una máquina puede ir a DOS obras el mismo día (mañana/tarde),
            // pero no duplicar la misma obra el mismo día.
            $table->unique(['maquina_id', 'proyecto_id', 'fecha']);

            // Queries del calendario: por rango de fechas y por máquina.
            $table->index(['fecha', 'maquina_id']);
            $table->index(['proyecto_id', 'fecha']);
        });

        // Horas previstas coherentes: más de 0 y máximo 24 por día.
        DB::statement(<<<'SQL'
            ALTER TABLE agenda_maquina
            ADD CONSTRAINT agenda_maquina_horas_check
            CHECK (horas_previstas > 0 AND horas_previstas <= 24)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_maquina');
    }
};
