<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La solicitud pide un RANGO de días ("del 17 al 19"), igual que el
 * Agendar del calendario. fecha_necesaria = primer día; fecha_hasta
 * null = un solo día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_maquina', function (Blueprint $table): void {
            $table->date('fecha_hasta')->nullable()->after('fecha_necesaria');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_maquina', function (Blueprint $table): void {
            $table->dropColumn('fecha_hasta');
        });
    }
};
