<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de cambios de mantenimiento realizados — cada vez que el
 * taller hace el cambio de aceite / puntas / cuchillas, queda UNA fila
 * con la fecha y las lecturas (horómetro, km) del momento. El plan
 * toma estos valores como nueva línea base y el contador arranca de
 * cero.
 *
 * Es bitácora pura: no se edita ni se borra desde la UI (el detalle
 * fino de costos/repuestos vive en el módulo de Mantenimientos si la
 * avería lo amerita).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios_mantenimiento', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('plan_mantenimiento_id')
                ->constrained('planes_mantenimiento')
                ->cascadeOnDelete();

            $table->date('fecha');
            $table->decimal('horometro', 12, 2)->nullable();
            $table->decimal('kilometraje', 12, 2)->nullable();

            $table->string('notas', 255)->nullable();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('plan_mantenimiento_id');
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_mantenimiento');
    }
};
