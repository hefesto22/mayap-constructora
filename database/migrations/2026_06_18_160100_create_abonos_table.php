<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `abonos` — pagos parciales o totales contra una cuenta por pagar.
 * Cada abono reduce el saldo de la cuenta. Bitácora de pagos al proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonos', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cuenta_por_pagar_id')
                ->constrained('cuentas_por_pagar')
                ->cascadeOnDelete();

            $table->decimal('monto', 14, 2);
            $table->date('fecha');

            $table->string('metodo', 50)
                ->nullable()
                ->comment('Efectivo, transferencia, cheque, etc.');

            $table->string('referencia', 100)
                ->nullable()
                ->comment('N.º de cheque, transferencia o comprobante.');

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index('cuenta_por_pagar_id');
            $table->index('fecha');
        });

        DB::statement(
            'ALTER TABLE abonos ADD CONSTRAINT abonos_monto_positivo
             CHECK (monto > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('abonos');
    }
};
