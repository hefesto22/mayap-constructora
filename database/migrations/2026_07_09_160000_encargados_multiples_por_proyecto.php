<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varios encargados por obra (ajuste sobre G1): si el titular no está,
 * otro encargado asignado puede pedir material y confirmar recepciones.
 *
 * Reemplaza proyectos.encargado_id (1:1) por la pivote proyecto_encargados
 * (N:M), migrando el dato existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyecto_encargados', function (Blueprint $table): void {
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['proyecto_id', 'user_id']);
            $table->index('user_id');
        });

        // Migrar el encargado único existente a la pivote.
        DB::statement(<<<'SQL'
            INSERT INTO proyecto_encargados (proyecto_id, user_id, created_at, updated_at)
            SELECT id, encargado_id, NOW(), NOW()
            FROM proyectos
            WHERE encargado_id IS NOT NULL
            SQL);

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('encargado_id');
        });
    }

    public function down(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->foreignId('encargado_id')
                ->nullable()
                ->after('cliente_id')
                ->constrained('users')
                ->restrictOnDelete();
        });

        // Restaurar el PRIMER encargado de cada obra (pérdida asumida si había varios).
        DB::statement(<<<'SQL'
            UPDATE proyectos SET encargado_id = (
                SELECT user_id FROM proyecto_encargados
                WHERE proyecto_encargados.proyecto_id = proyectos.id
                ORDER BY created_at LIMIT 1
            )
            SQL);

        Schema::dropIfExists('proyecto_encargados');
    }
};
