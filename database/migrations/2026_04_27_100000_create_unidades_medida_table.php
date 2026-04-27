<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)
                ->unique()
                ->comment('Código corto único: M2, M3, BOLSA, JDR, ML, KG, etc.');

            $table->string('nombre', 80)
                ->comment('Nombre completo: "Metro cuadrado", "Bolsa", "Jornada"');

            $table->string('simbolo', 10)
                ->nullable()
                ->comment('Símbolo de presentación opcional: m², m³, kg');

            $table->boolean('activo')
                ->default(true)
                ->comment('Permite deprecar sin borrar (preserva integridad de items históricos)');

            $table->timestamps();

            $table->index(['activo', 'codigo'], 'unidades_medida_activo_codigo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
    }
};
