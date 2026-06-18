<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `proyectos` — cotización de obra para un cliente.
 *
 * Un proyecto vive en UNA zona y solo puede consumir fichas de esa
 * zona (validación a nivel Service, las fichas referenciadas deben
 * tener zona_id == proyecto.zona_id).
 *
 * AUTO-CÓDIGO: PROY-{AÑO}-{NUMERO_5_DIGITOS}, ej: PROY-2026-00001.
 * Generado en el modelo con lockForUpdate para evitar colisiones.
 *
 * SNAPSHOT DE PRECIOS: los renglones (`proyecto_renglones`) copian el
 * precio_venta_cache de la ficha al momento de agregarse. Si los
 * precios cambian, los renglones existentes mantienen su snapshot.
 * Existe acción explícita "Actualizar precios" para refrescarlos.
 *
 * ESTADOS:
 *  - borrador: editable libremente (default al crear)
 *  - enviada: cotización formal enviada al cliente, no se editan
 *    renglones, solo cambia a aprobada/rechazada/vencida
 *  - aprobada: cliente aceptó, queda como base para Sprint 4 (ejecución)
 *  - rechazada: cliente rechazó, archivo histórico
 *  - vencida: pasó fecha_validez sin respuesta, marca automática diaria
 *
 * ISV: aplica_isv permite proyectos exentos (gobierno, ONG, etc.).
 * isv_porcentaje configurable por si cambia la tasa nacional.
 *
 * CACHE DE TOTALES: subtotal_cache + isv_cache + total_cache se
 * recalculan cada vez que cambian los renglones. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyectos', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 25)->unique();

            $table->foreignId('zona_id')
                ->comment('Zona inmutable después de crear el proyecto.')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('cliente_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('nombre', 255);
            $table->text('descripcion')->nullable();
            $table->text('direccion_obra');

            $table->date('fecha_emision');
            $table->date('fecha_validez');

            $table->string('estado', 20)->default('borrador');

            $table->string('moneda', 3)->default('HNL');

            $table->boolean('aplica_isv')->default(true);
            $table->decimal('isv_porcentaje', 5, 2)->default(15.00);

            $table->text('notas')->nullable();

            // Cache de totales calculados.
            $table->decimal('subtotal_cache', 14, 2)->default(0);
            $table->decimal('isv_cache', 14, 2)->default(0);
            $table->decimal('total_cache', 14, 2)->default(0);

            $table->timestamp('precio_calculado_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Índices para queries frecuentes.
            $table->index('estado');
            $table->index('fecha_emision');
            $table->index('fecha_validez');
            $table->index(['zona_id', 'estado']);   // listado filtrado por zona+estado
            $table->index(['cliente_id', 'estado']); // histórico por cliente
        });

        // CHECK: estado solo dentro de los valores permitidos.
        DB::statement(
            "ALTER TABLE proyectos ADD CONSTRAINT proyectos_estado_valido
             CHECK (estado IN ('borrador', 'enviada', 'aprobada', 'rechazada', 'vencida'))"
        );

        // CHECK: ISV en rango razonable.
        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_isv_porcentaje_valido
             CHECK (isv_porcentaje >= 0 AND isv_porcentaje <= 100)'
        );

        // CHECK: cache de totales no puede ser negativo.
        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_totales_no_negativos
             CHECK (subtotal_cache >= 0 AND isv_cache >= 0 AND total_cache >= 0)'
        );

        // CHECK: fecha de validez no puede ser anterior a la emisión.
        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_fechas_coherentes
             CHECK (fecha_validez >= fecha_emision)'
        );

        // CHECK: si NO aplica ISV, el isv_porcentaje DEBE ser 0.
        // Esto previene inconsistencias tipo "exento pero con 15%".
        DB::statement(
            'ALTER TABLE proyectos ADD CONSTRAINT proyectos_isv_consistente
             CHECK (aplica_isv = TRUE OR isv_porcentaje = 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proyectos');
    }
};
