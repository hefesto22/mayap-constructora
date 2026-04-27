<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zonas', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 10)
                ->unique()
                ->comment('Código corto único: SRC, TGU, SPS');

            $table->string('nombre', 120)
                ->comment('Nombre completo: "Santa Rosa de Copán", "Tegucigalpa"');

            $table->string('descripcion', 255)
                ->nullable()
                ->comment('Notas operativas opcionales sobre la zona');

            $table->boolean('activa')
                ->default(true)
                ->comment('Permite deprecar zonas sin afectar histórico de presupuestos');

            $table->timestamps();

            $table->index(['activa', 'nombre'], 'zonas_activa_nombre_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zonas');
    }
};
