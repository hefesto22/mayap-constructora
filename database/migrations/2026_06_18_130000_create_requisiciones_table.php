<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `requisiciones` — cabecera de un pedido de material de una obra.
 *
 * Es la columna vertebral del sistema (docs/arquitectura/sistema-completo.md §3):
 * una obra solicita material, Administración autoriza, Bodega despacha, la
 * obra confirma recepción. Cada transición queda registrada con su
 * responsable en `requisicion_transiciones`, para saber dónde y quién si
 * algo no cuadra.
 *
 * AUTO-CÓDIGO: REQ-{AÑO}-{NUMERO_5}, ej: REQ-2026-00001. El contador se
 * reinicia por año, igual que los proyectos. Generado en el modelo con
 * lockForUpdate.
 *
 * ESTADO: enum EstadoRequisicion. Máquina de estados con CHECK constraint
 * que valida el conjunto. La transición válida la controla el Service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisiciones', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 25)->unique();

            $table->foreignId('proyecto_id')
                ->comment('Obra que solicita el material.')
                ->constrained('proyectos')
                ->restrictOnDelete();

            $table->string('estado', 25)->default('solicitada');

            $table->foreignId('solicitante_id')
                ->nullable()
                ->comment('Usuario que creó la requisición (residente de obra).')
                ->constrained('users')
                ->nullOnDelete();

            $table->date('fecha_solicitud');
            $table->date('fecha_necesaria')
                ->comment('Fecha en que el material debe estar en obra sí o sí.');

            $table->text('notas')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
            $table->index('fecha_necesaria');
            $table->index(['proyecto_id', 'estado']);
        });

        DB::statement(
            "ALTER TABLE requisiciones ADD CONSTRAINT requisiciones_estado_valido
             CHECK (estado IN (
                'solicitada', 'autorizada', 'requisicion_compra', 'despachada',
                'en_transito', 'recibida', 'cerrada', 'discrepancia', 'rechazada'
             ))"
        );

        DB::statement(
            'ALTER TABLE requisiciones ADD CONSTRAINT requisiciones_fechas_coherentes
             CHECK (fecha_necesaria >= fecha_solicitud)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('requisiciones');
    }
};
