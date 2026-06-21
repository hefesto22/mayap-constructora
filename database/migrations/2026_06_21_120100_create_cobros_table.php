<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `cobros` — pago parcial o total que un cliente hace contra una cuenta
 * por cobrar. Espejo de `abonos`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cuenta_por_cobrar_id')
                ->constrained('cuentas_por_cobrar')
                ->cascadeOnDelete();

            $table->decimal('monto', 14, 2);
            $table->date('fecha');
            $table->string('metodo', 30)->nullable();
            $table->string('referencia', 100)->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index('cuenta_por_cobrar_id');
            $table->index('fecha');
        });

        DB::statement(
            'ALTER TABLE cobros ADD CONSTRAINT cobros_monto_positivo
             CHECK (monto > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros');
    }
};
