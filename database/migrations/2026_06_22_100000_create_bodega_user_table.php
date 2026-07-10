<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot `bodega_user` — asigna usuarios a bodegas (Fase 2: visibilidad por
 * bodega).
 *
 * Un usuario puede estar asignado a VARIAS bodegas (un gerente regional cubre
 * más de una). Los usuarios SIN el permiso `VerTodasLasBodegas:Bodega` solo ven
 * el stock, movimientos y compras de sus bodegas asignadas; los que tienen el
 * permiso (super_admin, gerencia) ven todo.
 *
 * La restricción se aplica a nivel de consulta/Resource y selectores (no como
 * Global Scope) para no interferir con operaciones legítimas cross-bodega del
 * motor de inventario (ej. traslados entre bodegas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bodega_user', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnDelete();

            $table->timestamps();

            // Un usuario no se asigna dos veces a la misma bodega.
            $table->primary(['user_id', 'bodega_id']);

            // Búsqueda inversa: usuarios de una bodega.
            $table->index('bodega_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bodega_user');
    }
};
