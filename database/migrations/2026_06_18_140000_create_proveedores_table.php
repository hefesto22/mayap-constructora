<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `proveedores` — entidad de negocio compartida entre compras de
 * bodega y (a futuro) repuestos de maquinaria.
 *
 * Espejo de `clientes` con el agregado de condición de pago (contado /
 * crédito) y días de crédito, que alimentan las cuentas por pagar.
 *
 * Auto-código en el modelo (PRV-#####). RTN nullable con unique parcial
 * (permite múltiples NULL) y CHECK de formato cuando está presente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();

            $table->string('nombre', 255);
            $table->string('rtn', 14)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('direccion')->nullable();
            $table->string('ciudad', 100)->nullable();

            $table->string('condicion_pago', 20)->default('contado');
            $table->unsignedSmallInteger('dias_credito')->default(0)
                ->comment('Días de crédito cuando la condición es crédito.');

            $table->text('notas')->nullable();

            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('nombre');
            $table->index('activo');
        });

        DB::statement(
            'CREATE UNIQUE INDEX proveedores_rtn_unique_when_not_null
             ON proveedores (rtn)
             WHERE rtn IS NOT NULL AND deleted_at IS NULL'
        );

        DB::statement(
            "ALTER TABLE proveedores ADD CONSTRAINT proveedores_rtn_formato_valido
             CHECK (rtn IS NULL OR rtn ~ '^\d{14}$')"
        );

        DB::statement(
            "ALTER TABLE proveedores ADD CONSTRAINT proveedores_email_formato_valido
             CHECK (email IS NULL OR email ~ '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$')"
        );

        DB::statement(
            "ALTER TABLE proveedores ADD CONSTRAINT proveedores_condicion_pago_valida
             CHECK (condicion_pago IN ('contado', 'credito'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
