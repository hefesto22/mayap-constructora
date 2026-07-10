<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encargado responsable de la obra (Fase G1 — operación por roles).
 *
 * UN usuario responsable por proyecto: es quien pide material (requisiciones)
 * y confirma lo que llega a la obra. El scoping "solo mis obras" de los
 * Resources se basa en este campo. Nullable: cotizaciones en fase comercial
 * aún no tienen encargado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->foreignId('encargado_id')
                ->nullable()
                ->after('cliente_id')
                ->comment('Usuario responsable de la obra (rol encargado_obra).')
                ->constrained('users')
                ->restrictOnDelete();

            $table->index('encargado_id');
        });
    }

    public function down(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('encargado_id');
        });
    }
};
