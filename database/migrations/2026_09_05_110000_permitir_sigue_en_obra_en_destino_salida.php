<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "TERMINÓ EL DÍA, SE QUEDÓ EN LA OBRA" (Mauricio, 2026-09-05).
 *
 * La versión anterior de esta columna asumía que "sigue en la obra" era
 * simplemente NO cerrar la estadía, y por eso no necesitaba valor. En la
 * práctica sí lo necesita: el encargado cierra el día TODOS los días —
 * registra horómetro y horas — y ahí mismo dice qué pasó con la máquina.
 * Quedarse es la respuesta más común, así que es una opción explícita (y
 * la que viene marcada), no la ausencia de respuesta.
 *
 * Con 'sigue_en_obra' el día cierra pero salida_confirmada_at sigue en
 * NULL: la máquina no se libera y el calendario la sigue pintando ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE agenda_maquina DROP CONSTRAINT IF EXISTS agenda_destino_salida_valido');

        DB::statement(
            "ALTER TABLE agenda_maquina ADD CONSTRAINT agenda_destino_salida_valido
             CHECK (
                 destino_salida IS NULL
                 OR destino_salida IN ('sigue_en_obra', 'bodega', 'otra_obra', 'taller')
             )"
        );

        // Coherencia: si la estadía está CERRADA, el destino no puede ser
        // "se quedó" — cerrar y quedarse son cosas opuestas.
        DB::statement('ALTER TABLE agenda_maquina DROP CONSTRAINT IF EXISTS agenda_destino_coherente_con_salida');

        DB::statement(
            "ALTER TABLE agenda_maquina ADD CONSTRAINT agenda_destino_coherente_con_salida
             CHECK (
                 salida_confirmada_at IS NULL
                 OR destino_salida IS NULL
                 OR destino_salida <> 'sigue_en_obra'
             )"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agenda_maquina DROP CONSTRAINT IF EXISTS agenda_destino_coherente_con_salida');
        DB::statement('ALTER TABLE agenda_maquina DROP CONSTRAINT IF EXISTS agenda_destino_salida_valido');

        DB::statement("UPDATE agenda_maquina SET destino_salida = NULL WHERE destino_salida = 'sigue_en_obra'");

        DB::statement(
            "ALTER TABLE agenda_maquina ADD CONSTRAINT agenda_destino_salida_valido
             CHECK (
                 destino_salida IS NULL
                 OR destino_salida IN ('bodega', 'otra_obra', 'taller')
             )"
        );
    }
};
