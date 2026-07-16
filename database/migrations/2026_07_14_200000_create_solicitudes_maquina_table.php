<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de maquinaria — el encargado de obra pide "esta máquina
 * para tal día a tal hora". La agenda decide: disponible → nace Agendada
 * (con su agendado vinculado); ocupada → nace Pendiente y el rol
 * maquinaria la resuelve. TODO queda como historial del proyecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_maquina', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->foreignId('maquina_id')->constrained('maquinas')->restrictOnDelete();
            $table->date('fecha_necesaria');
            $table->time('hora_llegada');
            $table->string('estado', 20)->default('pendiente');
            $table->text('notas')->nullable();

            // Trazabilidad: quién pidió, cómo se resolvió, cuándo y por qué.
            $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agenda_maquina_id')->nullable()->constrained('agenda_maquina')->nullOnDelete();
            $table->foreignId('resuelta_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelta_at')->nullable();
            $table->text('motivo')->nullable();

            $table->timestamps();

            $table->index(['proyecto_id', 'estado']);
        });

        DB::statement("
            ALTER TABLE solicitudes_maquina
            ADD CONSTRAINT solicitudes_maquina_estado_check
            CHECK (estado IN ('pendiente', 'agendada', 'rechazada'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_maquina');
    }
};
