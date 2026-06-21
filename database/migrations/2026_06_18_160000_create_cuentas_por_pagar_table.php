<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `cuentas_por_pagar` — saldo que se le debe a un proveedor por una
 * compra a crédito. Se genera al confirmar la compra y se reduce con abonos.
 *
 * Modelo simple saldos + abonos (ADR-0002 §5), sin partida doble. El estado
 * (pendiente/parcial/pagada) deriva del saldo y lo gestiona el AbonarService.
 *
 * Una cuenta por compra (unique compra_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_por_pagar', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('compra_id')
                ->constrained('compras')
                ->cascadeOnDelete();

            $table->foreignId('proveedor_id')
                ->comment('Denormalizado para reportes por proveedor.')
                ->constrained('proveedores')
                ->restrictOnDelete();

            $table->decimal('monto_original', 14, 2);
            $table->decimal('saldo', 14, 2);

            $table->date('fecha_emision');
            $table->date('fecha_vencimiento');

            $table->string('estado', 20)->default('pendiente');

            $table->timestamps();
            $table->softDeletes();

            $table->unique('compra_id', 'cuentas_por_pagar_compra_unique');
            $table->index('estado');
            $table->index(['proveedor_id', 'estado']);
            $table->index('fecha_vencimiento');
        });

        DB::statement(
            "ALTER TABLE cuentas_por_pagar ADD CONSTRAINT cuentas_por_pagar_estado_valido
             CHECK (estado IN ('pendiente', 'parcial', 'pagada'))"
        );

        DB::statement(
            'ALTER TABLE cuentas_por_pagar ADD CONSTRAINT cuentas_por_pagar_saldo_valido
             CHECK (saldo >= 0 AND saldo <= monto_original AND monto_original >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_por_pagar');
    }
};
