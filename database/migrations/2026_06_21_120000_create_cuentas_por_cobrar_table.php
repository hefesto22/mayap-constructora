<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `cuentas_por_cobrar` — lo que un cliente le debe a MAYAP. Espejo de
 * `cuentas_por_pagar` pero del lado de ingresos. Se registra directamente (no
 * hay facturación automática todavía) y se baja con cobros.
 *
 * Puede ligarse a una obra (proyecto) para saber por qué se debe. Auto-código
 * CXC-{AÑO}-##### en el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_por_cobrar', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo', 20)->unique();

            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->restrictOnDelete();

            $table->foreignId('proyecto_id')->nullable()
                ->constrained('proyectos')
                ->nullOnDelete();

            $table->string('concepto', 255)->nullable();

            $table->decimal('monto_original', 14, 2);
            $table->decimal('saldo', 14, 2);

            $table->date('fecha_emision');
            $table->date('fecha_vencimiento');

            $table->string('estado', 20)->default('pendiente');

            $table->text('notas')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('cliente_id');
            $table->index('proyecto_id');
            $table->index('estado');
            $table->index('fecha_vencimiento');
        });

        DB::statement(
            "ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_estado_valido
             CHECK (estado IN ('pendiente', 'parcial', 'pagada'))"
        );

        DB::statement(
            'ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_saldo_valido
             CHECK (saldo >= 0 AND saldo <= monto_original AND monto_original >= 0)'
        );

        DB::statement(
            'ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_fechas_coherentes
             CHECK (fecha_vencimiento >= fecha_emision)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_por_cobrar');
    }
};
