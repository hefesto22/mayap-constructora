<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `movimientos_inventario` — libro mayor INMUTABLE del inventario.
 *
 * Cada fila es un movimiento físico registrado: entrada por compra, salida
 * a obra, traslado, consumo, devolución o ajuste. Es la fuente de verdad
 * auditable: las existencias son el saldo derivado, los movimientos son la
 * bitácora que lo explica. NUNCA se editan ni borran (corrección = nuevo
 * movimiento inverso).
 *
 * UBICACIONES origen/destino: cada una puede ser bodega o proyecto, según
 * el tipo (ver enum TipoMovimientoInventario):
 *  - EntradaCompra / AjustePositivo: solo destino.
 *  - SalidaDespacho / ConsumoObra / AjusteNegativo: solo origen.
 *  - Traslado / Devolucion: origen Y destino.
 * Cada lado se modela con bodega_*_id nullable + proyecto_*_id nullable;
 * los CHECK garantizan que un lado activo tenga exactamente una ubicación.
 *
 * COSTEO: `costo_unitario` es el costo aplicado al movimiento (costo de
 * compra en entradas; promedio ponderado vigente en salidas/traslados).
 * `valor_total` = cantidad × costo_unitario. Permite reconstruir el WAC.
 *
 * REFERENCIA polimórfica (`referencia_type` / `referencia_id`): enlaza el
 * movimiento a su origen de negocio (una requisición, una compra) sin
 * acoplar la tabla a un módulo específico. Nullable para ajustes manuales.
 *
 * `motivo` es obligatorio (a nivel Service) para ajustes y mermas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table): void {
            $table->id();

            $table->string('tipo', 30)
                ->comment('Enum TipoMovimientoInventario: entrada_compra, salida_despacho, traslado, consumo_obra, devolucion, ajuste_positivo, ajuste_negativo');

            $table->foreignId('material_id')
                ->constrained('materiales')
                ->restrictOnDelete();

            // Ubicación de ORIGEN (de dónde sale el stock).
            $table->foreignId('bodega_origen_id')
                ->nullable()
                ->constrained('bodegas')
                ->restrictOnDelete();

            $table->foreignId('proyecto_origen_id')
                ->nullable()
                ->constrained('proyectos')
                ->restrictOnDelete();

            // Ubicación de DESTINO (a dónde entra el stock).
            $table->foreignId('bodega_destino_id')
                ->nullable()
                ->constrained('bodegas')
                ->restrictOnDelete();

            $table->foreignId('proyecto_destino_id')
                ->nullable()
                ->constrained('proyectos')
                ->restrictOnDelete();

            $table->decimal('cantidad', 16, 4)
                ->comment('Cantidad movida. Siempre > 0 (la dirección la da el tipo).');

            $table->decimal('costo_unitario', 16, 4)
                ->default(0)
                ->comment('Costo aplicado: compra en entradas, WAC vigente en salidas.');

            $table->decimal('valor_total', 16, 2)
                ->default(0)
                ->comment('cantidad × costo_unitario, en HNL.');

            $table->text('motivo')
                ->nullable()
                ->comment('Justificación. Obligatorio (Service) para ajustes y mermas.');

            // Referencia polimórfica al documento de negocio que lo originó.
            $table->nullableMorphs('referencia');

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('fecha')
                ->comment('Fecha contable del movimiento (puede diferir de created_at).');

            $table->timestamps();

            $table->index('material_id');
            $table->index('tipo');
            $table->index('fecha');
            $table->index(['material_id', 'fecha']);
        });

        // CHECK: tipo dentro del conjunto válido.
        DB::statement(
            "ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_tipo_valido
             CHECK (tipo IN (
                'entrada_compra', 'salida_despacho', 'traslado', 'consumo_obra',
                'devolucion', 'ajuste_positivo', 'ajuste_negativo'
             ))"
        );

        // CHECK: cantidad estrictamente positiva; costos no negativos.
        DB::statement(
            'ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_montos_validos
             CHECK (cantidad > 0 AND costo_unitario >= 0 AND valor_total >= 0)'
        );

        // CHECK: el lado ORIGEN, si está activo, tiene como máximo una
        // ubicación (bodega o proyecto, no ambas).
        DB::statement(
            'ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_origen_una_ubicacion
             CHECK (
                (CASE WHEN bodega_origen_id   IS NOT NULL THEN 1 ELSE 0 END) +
                (CASE WHEN proyecto_origen_id IS NOT NULL THEN 1 ELSE 0 END) <= 1
             )'
        );

        // CHECK: el lado DESTINO, si está activo, tiene como máximo una ubicación.
        DB::statement(
            'ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_destino_una_ubicacion
             CHECK (
                (CASE WHEN bodega_destino_id   IS NOT NULL THEN 1 ELSE 0 END) +
                (CASE WHEN proyecto_destino_id IS NOT NULL THEN 1 ELSE 0 END) <= 1
             )'
        );

        // CHECK: todo movimiento tiene al menos un lado (origen o destino).
        DB::statement(
            'ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_tiene_ubicacion
             CHECK (
                bodega_origen_id   IS NOT NULL OR proyecto_origen_id  IS NOT NULL OR
                bodega_destino_id  IS NOT NULL OR proyecto_destino_id IS NOT NULL
             )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
