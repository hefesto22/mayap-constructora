<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GASTOS DE REPARACIÓN (Mauricio, 2026-09-05).
 *
 * "Si es el encargado el que compra puede registrarlo él; si no, tiene
 * que aparecerle a recepción que debe agregar ese gasto." De ahí las dos
 * puertas: el encargado anota lo que pagó en la obra y recepción lo
 * concilia con su factura, o recepción lo registra directo.
 *
 * A QUIÉN SE CARGA: "ese gasto se le carga a la máquina para el
 * historial, pero dirá en qué obra sucedió, y con opción de decidir si
 * agregarlo al proyecto, a decisión de recepción." Por eso `maquina_id`
 * es obligatorio, `proyecto_id` es dónde pasó, y `cargar_a_proyecto` es
 * la decisión — solo con ella el monto entra al costo de la obra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos_mantenimiento', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 20)->unique();

            $table->foreignId('mantenimiento_id')->constrained('mantenimientos_maquina')->cascadeOnDelete();
            // Redundante con el mantenimiento a propósito: la hoja de vida
            // de la máquina se consulta por máquina, no por reparación.
            $table->foreignId('maquina_id')->constrained('maquinas')->restrictOnDelete();
            // DÓNDE sucedió. Null = estaba en el patio.
            $table->foreignId('proyecto_id')->nullable()->constrained('proyectos')->nullOnDelete();

            $table->date('fecha');
            $table->text('descripcion');
            $table->decimal('monto', 14, 2);

            // Quién lo metió: el de la obra (falta conciliar) o el que
            // compra (ya viene con respaldo).
            $table->string('origen', 20)->default('recepcion');
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            // La compra formal, cuando recepción la ligó.
            $table->foreignId('compra_id')->nullable()->constrained('compras')->nullOnDelete();

            // La decisión de recepción: ¿además se le carga a la obra?
            $table->boolean('cargar_a_proyecto')->default(false);

            $table->timestamp('conciliado_at')->nullable();
            $table->foreignId('conciliado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notas')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['maquina_id', 'fecha']);
            $table->index(['proyecto_id', 'cargar_a_proyecto']);
            $table->index('conciliado_at');
        });

        DB::statement(
            "ALTER TABLE gastos_mantenimiento ADD CONSTRAINT gasto_mantenimiento_valido
             CHECK (
                 monto >= 0
                 AND origen IN ('encargado', 'recepcion')
                 AND (cargar_a_proyecto = false OR proyecto_id IS NOT NULL)
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos_mantenimiento');
    }
};
