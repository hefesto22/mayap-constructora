<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `clientes` — entidad de negocio compartida entre proyectos.
 *
 * Un cliente puede tener N proyectos. Sus datos de contacto viven aquí
 * y NO se "snapshot" en los proyectos: si el cliente actualiza su
 * teléfono o dirección, las cotizaciones existentes apuntan al mismo
 * cliente_id y muestran los datos actualizados. Esto está bien porque
 * NO afecta el monto cotizado — solo metadatos descriptivos.
 *
 * Auto-código en el modelo (CLI-#####).
 *
 * RTN nullable porque clientes individuales sin RTN o clientes nuevos
 * pueden no tenerlo registrado al momento de cotizar. CHECK garantiza
 * formato cuando está presente. Unique parcial en Postgres usa
 * createIndex con condición para permitir múltiples NULLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();

            $table->string('nombre', 255);
            $table->string('rtn', 14)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('direccion')->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->text('notas')->nullable();

            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Índices para búsquedas frecuentes.
            $table->index('nombre');
            $table->index('activo');
        });

        // Unique parcial en RTN: permite múltiples NULLs pero rechaza
        // duplicados cuando hay valor.
        DB::statement(
            'CREATE UNIQUE INDEX clientes_rtn_unique_when_not_null
             ON clientes (rtn)
             WHERE rtn IS NOT NULL AND deleted_at IS NULL'
        );

        // CHECK: RTN siempre debe tener exactamente 14 dígitos numéricos
        // cuando no es nulo. Defensa última contra inserts directos.
        DB::statement(
            "ALTER TABLE clientes ADD CONSTRAINT clientes_rtn_formato_valido
             CHECK (rtn IS NULL OR rtn ~ '^\d{14}$')"
        );

        // CHECK: email con formato razonable cuando no es nulo.
        DB::statement(
            "ALTER TABLE clientes ADD CONSTRAINT clientes_email_formato_valido
             CHECK (email IS NULL OR email ~ '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
