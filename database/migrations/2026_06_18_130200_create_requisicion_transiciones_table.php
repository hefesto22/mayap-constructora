<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `requisicion_transiciones` — bitácora INMUTABLE de cada cambio de
 * estado de una requisición.
 *
 * Es el registro de auditoría que responde "¿quién hizo qué y cuándo?" en
 * cada eslabón del flujo (docs/arquitectura/sistema-completo.md §3). Cada
 * fila guarda el estado de origen, el de destino, el usuario responsable,
 * la cantidad confirmada en ese paso (cuando aplica) y una nota opcional
 * ("tengo 500, las otras llegan el viernes").
 *
 * NUNCA se edita ni borra: es la fuente de verdad de la trazabilidad. La
 * primera transición (creación) tiene estado_origen NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicion_transiciones', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('requisicion_id')
                ->constrained('requisiciones')
                ->cascadeOnDelete();

            $table->string('estado_origen', 25)
                ->nullable()
                ->comment('Estado previo. NULL en la transición de creación.');

            $table->string('estado_destino', 25);

            $table->foreignId('user_id')
                ->nullable()
                ->comment('Responsable de la transición.')
                ->constrained('users')
                ->nullOnDelete();

            $table->text('nota')->nullable();

            $table->timestamps();

            $table->index('requisicion_id');
            $table->index(['requisicion_id', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE requisicion_transiciones ADD CONSTRAINT requisicion_transiciones_estados_validos
             CHECK (
                (estado_origen IS NULL OR estado_origen IN (
                    'solicitada', 'autorizada', 'requisicion_compra', 'despachada',
                    'en_transito', 'recibida', 'cerrada', 'discrepancia', 'rechazada'
                ))
                AND estado_destino IN (
                    'solicitada', 'autorizada', 'requisicion_compra', 'despachada',
                    'en_transito', 'recibida', 'cerrada', 'discrepancia', 'rechazada'
                )
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicion_transiciones');
    }
};
