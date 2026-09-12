<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EN QUÉ SE LO LLEVARON (Mauricio, 2026-09-10).
 *
 * "Los materiales que salen de bodega salen en volquetas o autos de la
 * constructora, se cargan y salen a dejarlo y regresan uno o dos días
 * después."
 *
 * Hasta hoy el despacho movía el stock de bodega a la obra en el MISMO
 * instante. Si la volqueta tardaba dos días, durante dos días el sistema
 * juraba que la obra tenía material que iba rodando en la carretera — y
 * si algo se caía del camión, la pérdida aparecía como si hubiera pasado
 * en la obra. "En tránsito" era un rótulo, no un lugar.
 *
 * Con el vehículo declarado, el camión ES el lugar (es una bodega móvil,
 * la misma idea de la pipa):
 *
 *     cargar   → bodega  → camión   (traslado: sigue siendo nuestro)
 *     la obra confirma → camión → obra  (despacho: ahí pega el costo)
 *
 * En medio, el stock está exactamente donde está el material.
 *
 * `asignacion_viaje_id` es lo que ocupa la volqueta en el calendario
 * mientras anda repartiendo, para que nadie la agende a otra obra. Se
 * cierra cuando el viaje termina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisiciones', function (Blueprint $table): void {
            $table->foreignId('vehiculo_id')->nullable()->after('origen_despacho')
                ->constrained('bodegas')->nullOnDelete();
            $table->foreignId('asignacion_viaje_id')->nullable()->after('vehiculo_id')
                ->constrained('asignaciones_maquina')->nullOnDelete();
        });

        // La asignación del viaje solo existe si hay viaje: sin vehículo
        // declarado no hay nada que ocupar en el calendario.
        DB::statement(
            'ALTER TABLE requisiciones ADD CONSTRAINT requisicion_viaje_con_vehiculo
             CHECK (asignacion_viaje_id IS NULL OR vehiculo_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE requisiciones DROP CONSTRAINT IF EXISTS requisicion_viaje_con_vehiculo');

        Schema::table('requisiciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('asignacion_viaje_id');
            $table->dropConstrainedForeignId('vehiculo_id');
        });
    }
};
