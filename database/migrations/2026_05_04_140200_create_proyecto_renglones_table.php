<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `proyecto_renglones` — líneas de un proyecto/cotización.
 *
 * Cada renglón es: una FICHA APU × una CANTIDAD = un SUBTOTAL.
 * Ej: 120.5 M² × L 2,604.37/M² = L 313,826.59.
 *
 * SNAPSHOT DE PRECIO: `precio_unitario_snapshot` se copia del
 * `precio_venta_cache` de la ficha al agregar el renglón. NO cambia
 * automáticamente cuando la ficha se recalcula. Esto preserva la
 * integridad comercial de cotizaciones ya enviadas al cliente.
 *
 * Para refrescar todos los snapshots de un proyecto a los precios
 * actuales, existe la acción `ActualizarPreciosProyectoService` —
 * uso explícito y consciente.
 *
 * CAPÍTULO: campo string libre (ej: "01 PRELIMINARES", "02 CIMENTACIÓN")
 * que agrupa los renglones en el PDF y la UI. NO es FK a tabla — el
 * usuario escribe libre, el form sugiere los previos.
 *
 * CASCADE: si se elimina el proyecto, sus renglones se eliminan.
 * Si se intenta eliminar una ficha con renglones, se rechaza
 * (restrictOnDelete) — proteccion contra borrado inadvertido.
 *
 * ÍNDICES: orden compuesto (proyecto_id, orden) para el listado en
 * el form Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyecto_renglones', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('ficha_id')
                ->comment('Ficha APU referenciada. Su zona DEBE coincidir con la del proyecto.')
                ->constrained()
                ->restrictOnDelete();

            $table->unsignedInteger('orden')->default(0);

            $table->string('capitulo', 100)->nullable();

            $table->decimal('cantidad', 12, 4);
            $table->decimal('precio_unitario_snapshot', 14, 2);
            $table->decimal('subtotal_cache', 14, 2)->default(0);

            $table->text('notas')->nullable();

            $table->timestamps();

            // Índices para form y reportes.
            $table->index(['proyecto_id', 'orden']);
            $table->index('ficha_id');
            $table->index('capitulo');
        });

        // CHECK: cantidad estrictamente positiva (un renglón con cantidad 0
        // no tiene sentido — si quieres quitarlo, elimina el renglón).
        DB::statement(
            'ALTER TABLE proyecto_renglones ADD CONSTRAINT proyecto_renglones_cantidad_positiva
             CHECK (cantidad > 0)'
        );

        // CHECK: precio snapshot no puede ser negativo.
        DB::statement(
            'ALTER TABLE proyecto_renglones ADD CONSTRAINT proyecto_renglones_precio_no_negativo
             CHECK (precio_unitario_snapshot >= 0)'
        );

        // CHECK: subtotal_cache coherente con cantidad × precio (margen
        // de 0.01 por redondeo de NUMERIC). Defensa última contra
        // updates manuales que olviden recalcular.
        DB::statement(
            'ALTER TABLE proyecto_renglones ADD CONSTRAINT proyecto_renglones_subtotal_coherente
             CHECK (
                subtotal_cache >= 0
                AND ABS(subtotal_cache - (cantidad * precio_unitario_snapshot)) < 0.02
             )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_renglones');
    }
};
