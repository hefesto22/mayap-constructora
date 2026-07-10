<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Renombra el permiso custom `ver_todas_las_bodegas` (snake, Fase 2) a
 * `VerTodasLasBodegas:Bodega` — el patrón PascalCase {Accion}:{Modelo} de
 * Shield. Razón: la pestaña "Personalizados" de la pantalla de Roles pasa
 * los nombres por Str::studly; un nombre snake quedaría desalineado con la
 * base de datos y guardar el rol crearía un permiso duplicado inservible.
 *
 * Solo cambia el name: los roles/usuarios que lo tenían lo conservan
 * (misma fila, mismo id). Ver App\Support\Permisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('name', 'ver_todas_las_bodegas')
            ->where('guard_name', 'web')
            ->update(['name' => 'VerTodasLasBodegas:Bodega']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'VerTodasLasBodegas:Bodega')
            ->where('guard_name', 'web')
            ->update(['name' => 'ver_todas_las_bodegas']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
