<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `bodegas` — catálogo de bodegas físicas de la constructora.
 *
 * Una bodega es una ubicación de stock real (almacén central, bodega de
 * zona). Junto con los `proyectos`, son los dos tipos de ubicación donde
 * vive el inventario (ver `existencias`).
 *
 * AUTO-CÓDIGO: BOD-{NUMERO_5_DIGITOS}, ej: BOD-00001. Generado en el
 * modelo con lockForUpdate para evitar colisiones bajo concurrencia,
 * patrón consistente con items/proyectos.
 *
 * No se elimina físicamente: `activo = false` la retira de uso pero
 * preserva el histórico de movimientos que la referencian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bodegas', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();

            $table->string('nombre', 200)
                ->comment('Nombre operativo: "Bodega Central Santa Rosa"');

            $table->text('direccion')
                ->nullable()
                ->comment('Ubicación física de la bodega');

            $table->string('responsable', 150)
                ->nullable()
                ->comment('Persona a cargo del control de la bodega');

            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bodegas');
    }
};
